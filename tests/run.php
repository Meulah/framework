<?php

declare(strict_types=1);

use Meulah\Application;
use Meulah\Config\Repository;
use Meulah\Database\Connection;
use Meulah\Database\Migration;
use Meulah\Database\Migrator;
use Meulah\Exception\ExceptionHandler;
use Meulah\Http\Request;
use Meulah\Http\Response;
use Meulah\Log\Logger;
use Meulah\Routing\MethodNotAllowed;
use Meulah\Routing\RouteNotFound;
use Meulah\Routing\Router;
use Meulah\View\View;

require __DIR__ . '/bootstrap.php';

$tests = [];

$test = static function (string $name, Closure $callback) use (&$tests): void {
    $tests[$name] = $callback;
};

$assertSame = static function (mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Expected %s, received %s.",
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
};

$test('request exposes normalized input', static function () use ($assertSame): void {
    $request = new Request('POST', '/users/login', ['page' => '2'], ['email' => 'dev@example.com']);

    $assertSame('POST', $request->method());
    $assertSame('/users/login', $request->path());
    $assertSame('2', $request->query('page'));
    $assertSame('dev@example.com', $request->input('email'));
});

$test('router dispatches static routes', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/health', static fn (): Response => new Response('ok'));

    $response = $router->dispatch(new Request('GET', '/health'));

    $assertSame('ok', $response->content());
});

$test('router passes decoded route parameters', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/users/{user}', static fn (string $user): string => $user);

    $assertSame('Ada Lovelace', $router->dispatch(new Request('GET', '/users/Ada%20Lovelace')));
});

$test('router distinguishes method mismatch from missing route', static function (): void {
    $router = new Router();
    $router->post('/messages', static fn (): null => null);

    try {
        $router->dispatch(new Request('GET', '/messages'));
        throw new RuntimeException('Expected MethodNotAllowed.');
    } catch (MethodNotAllowed $exception) {
        if ($exception->allowedMethods !== ['POST']) {
            throw new RuntimeException('Incorrect allowed methods.');
        }
    }

    try {
        $router->dispatch(new Request('GET', '/missing'));
        throw new RuntimeException('Expected RouteNotFound.');
    } catch (RouteNotFound) {
    }
});

$test('application converts routing failures into HTTP responses', static function () use ($assertSame): void {
    $router = new Router();
    $router->post('/messages', static fn (): Response => new Response('', 204));
    $application = new Application($router);

    $notAllowed = $application->handle(new Request('GET', '/messages'));
    $notFound = $application->handle(new Request('GET', '/missing'));

    $assertSame(405, $notAllowed->status());
    $assertSame('POST', $notAllowed->headers()['Allow']);
    $assertSame(404, $notFound->status());
});

$test('configuration supports nested values and strict types', static function () use ($assertSame): void {
    $config = new Repository([
        'app' => ['environment' => 'testing', 'debug' => true],
        'database' => ['port' => 3306],
    ]);

    $assertSame(true, $config->has('app.debug'));
    $assertSame('testing', $config->string('app.environment'));
    $assertSame(true, $config->bool('app.debug'));
    $assertSame(3306, $config->int('database.port'));
    $assertSame('fallback', $config->get('missing', 'fallback'));
});

$test('configuration loads root configuration files', static function () use ($assertSame): void {
    $config = Repository::load(dirname(__DIR__) . '/config');

    $assertSame(true, $config->has('app.environment'));
    $assertSame('mysql', $config->string('database.driver'));
});

$test('exception handler hides production details and logs failures', static function () use ($assertSame): void {
    $logger = new class implements Logger {
        public array $exceptions = [];

        public function error(Throwable $exception): void
        {
            $this->exceptions[] = $exception;
        }
    };
    $handler = new ExceptionHandler(false, $logger);
    $response = $handler->render(new RuntimeException('database password leaked'));

    $assertSame(500, $response->status());
    $assertSame(false, str_contains($response->content(), 'database password leaked'));
    $assertSame(1, count($logger->exceptions));
});

$test('debug exceptions are escaped before rendering', static function () use ($assertSame): void {
    $logger = new class implements Logger {
        public function error(Throwable $exception): void
        {
        }
    };
    $handler = new ExceptionHandler(true, $logger);
    $response = $handler->render(new RuntimeException('<script>alert(1)</script>'));

    $assertSame(false, str_contains($response->content(), '<script>'));
    $assertSame(true, str_contains($response->content(), '&lt;script&gt;'));
});

$test('view renderer isolates rendering behind a configured path', static function () use ($assertSame): void {
    $views = new View(__DIR__ . '/fixtures/views');

    $assertSame('Hello, Meulah!', trim($views->render('greeting', ['name' => 'Meulah'])));
});

$test('database connection executes migrations transactionally', static function () use ($assertSame): void {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        return;
    }

    $connection = new Connection(new \PDO('sqlite::memory:'));
    $migration = new class implements Migration {
        public function up(Connection $connection): void
        {
            $connection->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
            $connection->execute('INSERT INTO notes (body) VALUES (:body)', ['body' => 'small and explicit']);
        }

        public function down(Connection $connection): void
        {
            $connection->execute('DROP TABLE notes');
        }
    };

    $migrator = new Migrator($connection);
    $migrator->run($migration);
    $assertSame(1, $connection->scalar('SELECT COUNT(*) FROM notes'));
    $migrator->rollback($migration);
});

$test('database identifiers are strictly validated', static function () use ($assertSame): void {
    $assertSame('users', Connection::identifier('users'));

    try {
        Connection::identifier('users; DROP TABLE users');
        throw new RuntimeException('Expected invalid identifier rejection.');
    } catch (InvalidArgumentException) {
    }
});

$failures = 0;

foreach ($tests as $name => $callback) {
    try {
        $callback();
        echo "PASS {$name}" . PHP_EOL;
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}" . PHP_EOL);
    }
}

echo PHP_EOL . sprintf('%d passed, %d failed.', count($tests) - $failures, $failures) . PHP_EOL;
exit($failures === 0 ? 0 : 1);
