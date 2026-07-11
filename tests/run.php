<?php

declare(strict_types=1);

use Meulah\Application;
use Meulah\Config\Repository;
use Meulah\Database\Connection;
use Meulah\Database\Migration;
use Meulah\Database\MigrationFinder;
use Meulah\Database\MigrationFile;
use Meulah\Database\MigrationRepository;
use Meulah\Database\Migrator;
use Meulah\Exception\ExceptionHandler;
use Meulah\Http\Request;
use Meulah\Http\Middleware;
use Meulah\Http\RequestHandler;
use Meulah\Http\Response;
use Meulah\Http\UploadedFile;
use Meulah\Log\Logger;
use Meulah\Routing\MethodNotAllowed;
use Meulah\Routing\RouteNotFound;
use Meulah\Routing\Router;
use Meulah\Support\Environment;
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
    $request = new Request('post', '/users/login/', ['page' => '2'], ['email' => 'dev@example.com']);

    $assertSame('POST', $request->method());
    $assertSame('/users/login', $request->path());
    $assertSame('2', $request->query('page'));
    $assertSame('dev@example.com', $request->input('email'));
});

$test('captured requests hide the internal rewrite parameter', static function () use ($assertSame): void {
    $originalGet = $_GET;
    $originalPost = $_POST;
    $originalServer = $_SERVER;

    try {
        $_GET = ['url' => 'articles/42', 'page' => '2'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = Request::capture();

        $assertSame('/articles/42', $request->path());
        $assertSame('2', $request->query('page'));
        $assertSame(null, $request->query('url'));
    } finally {
        $_GET = $originalGet;
        $_POST = $originalPost;
        $_SERVER = $originalServer;
    }
});

$test('request exposes headers JSON cookies and files', static function () use ($assertSame): void {
    $upload = new UploadedFile('avatar.png', 'image/png', 'C:/tmp/upload', UPLOAD_ERR_OK, 2048);
    $request = new Request(
        'POST',
        '/profile',
        ['source' => 'query'],
        ['name' => 'form-name'],
        [],
        ['Content-Type' => 'application/vnd.api+json; charset=UTF-8', 'X-Trace' => 'abc123'],
        ['session' => 'cookie-value'],
        ['avatar' => $upload],
        '{"name":"json-name","active":true}',
    );

    $assertSame('abc123', $request->header('x-trace'));
    $assertSame('application/vnd.api+json; charset=UTF-8', $request->header('CONTENT-TYPE'));
    $assertSame('cookie-value', $request->cookie('session'));
    $assertSame($upload, $request->file('avatar'));
    $assertSame('{"name":"json-name","active":true}', $request->rawBody());
    $assertSame(true, $request->json('active'));
    $assertSame('json-name', $request->input('name'));
    $assertSame('query', $request->input('source'));
});

$test('captured requests normalize nested PHP uploads', static function () use ($assertSame): void {
    $originalGet = $_GET;
    $originalPost = $_POST;
    $originalCookie = $_COOKIE;
    $originalFiles = $_FILES;
    $originalServer = $_SERVER;

    try {
        $_GET = ['url' => 'upload'];
        $_POST = [];
        $_COOKIE = ['theme' => 'dark'];
        $_FILES = [
            'documents' => [
                'name' => ['first.txt', 'second.txt'],
                'type' => ['text/plain', 'text/plain'],
                'tmp_name' => ['C:/tmp/first', 'C:/tmp/second'],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
                'size' => [10, 0],
            ],
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'request-1';
        $request = Request::capture();
        $documents = $request->file('documents');

        $assertSame('request-1', $request->header('x-request-id'));
        $assertSame('dark', $request->cookie('theme'));
        $assertSame('first.txt', $documents[0]->clientFilename());
        $assertSame(UPLOAD_ERR_NO_FILE, $documents[1]->error());
    } finally {
        $_GET = $originalGet;
        $_POST = $originalPost;
        $_COOKIE = $originalCookie;
        $_FILES = $originalFiles;
        $_SERVER = $originalServer;
    }
});

$test('malformed JSON is rendered as a bad request', static function () use ($assertSame): void {
    $router = new Router();
    $router->post('/json', static function (Request $request): string {
        $request->json();
        return 'ok';
    });
    $application = new Application($router);
    $request = new Request(
        'POST',
        '/json',
        headers: ['Content-Type' => 'application/json'],
        rawBody: '{broken',
    );

    $response = $application->handle($request);

    $assertSame(400, $response->status());
    $assertSame(false, str_contains($response->content(), '{broken'));
});

$test('route handlers can receive the request before path parameters', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/articles/{article}', static function (Request $request, string $article): string {
        return $request->header('x-language') . ':' . $article;
    });

    $response = $router->dispatch(new Request(
        'GET',
        '/articles/42',
        headers: ['X-Language' => 'en'],
    ));

    $assertSame('en:42', $response->content());
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

    $assertSame(
        'Ada Lovelace',
        $router->dispatch(new Request('GET', '/users/Ada%20Lovelace'))->content(),
    );
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

$test('HEAD responses never include a body', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/health', static fn (): string => 'healthy');
    $application = new Application($router);

    $response = $application->handle(new Request('HEAD', '/health'));

    $assertSame(200, $response->status());
    $assertSame('', $response->content());
    $assertSame('text/html; charset=UTF-8', $response->headers()['Content-Type']);
});

$test('global and route middleware run in registration order', static function () use ($assertSame): void {
    $events = new ArrayObject();
    $makeMiddleware = static function (string $name) use ($events): Middleware {
        return new class($name, $events) implements Middleware {
            public function __construct(
                private readonly string $name,
                private readonly ArrayObject $events,
            ) {
            }

            public function process(Request $request, RequestHandler $next): Response
            {
                $this->events[] = $this->name . ':before';
                $response = $next->handle($request);
                $this->events[] = $this->name . ':after';
                return $response->withHeader('X-' . $this->name, 'applied');
            }
        };
    };

    $router = new Router();
    $route = $router->get('/middleware', static function () use ($events): string {
        $events[] = 'handler';
        return 'ok';
    });
    $route->middleware($makeMiddleware('Route'));
    $application = new Application($router);
    $application->middleware($makeMiddleware('First'), $makeMiddleware('Second'));

    $response = $application->handle(new Request('GET', '/middleware'));

    $assertSame([
        'First:before',
        'Second:before',
        'Route:before',
        'handler',
        'Route:after',
        'Second:after',
        'First:after',
    ], $events->getArrayCopy());
    $assertSame('applied', $response->headers()['X-First']);
    $assertSame('applied', $response->headers()['X-Route']);
});

$test('middleware can short circuit before a route handler', static function () use ($assertSame): void {
    $handled = false;
    $router = new Router();
    $router->get('/private', static function () use (&$handled): string {
        $handled = true;
        return 'private';
    });
    $application = new Application($router);
    $application->middleware(new class implements Middleware {
        public function process(Request $request, RequestHandler $next): Response
        {
            return Response::html('Forbidden', 403);
        }
    });

    $response = $application->handle(new Request('GET', '/private'));

    $assertSame(403, $response->status());
    $assertSame('Forbidden', $response->content());
    $assertSame(false, $handled);
});

$test('middleware exceptions use the configured exception handler', static function () use ($assertSame): void {
    $logger = new class implements Logger {
        public int $errors = 0;

        public function error(Throwable $exception): void
        {
            $this->errors++;
        }
    };
    $router = new Router();
    $router->get('/', static fn (): string => 'home');
    $application = new Application(
        $router,
        new Repository(),
        new ExceptionHandler(false, $logger),
    );
    $application->middleware(new class implements Middleware {
        public function process(Request $request, RequestHandler $next): Response
        {
            throw new RuntimeException('Middleware failed.');
        }
    });

    $response = $application->handle(new Request('GET', '/'));

    $assertSame(500, $response->status());
    $assertSame(1, $logger->errors);
    $assertSame(false, str_contains($response->content(), 'Middleware failed.'));
});

$test('responses reject invalid status codes and headers early', static function (): void {
    try {
        new Response('', 700);
        throw new RuntimeException('Expected invalid status rejection.');
    } catch (InvalidArgumentException) {
    }

    try {
        new Response('', 200, ['Location' => "/safe\r\nX-Injected: yes"]);
        throw new RuntimeException('Expected invalid header rejection.');
    } catch (InvalidArgumentException) {
    }
});

$test('response header replacement is case insensitive', static function () use ($assertSame): void {
    $response = (new Response('', 200, ['X-Trace' => 'first']))
        ->withHeader('x-trace', 'second');

    $assertSame(['x-trace' => 'second'], $response->headers());
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

$test('environment reads server values before defaults', static function () use ($assertSame): void {
    $key = 'MEULAH_TEST_ENVIRONMENT_VALUE';
    $original = $_SERVER[$key] ?? null;
    $existed = array_key_exists($key, $_SERVER);

    try {
        $_SERVER[$key] = 'from-server';
        $assertSame('from-server', Environment::get($key, 'fallback'));
    } finally {
        if ($existed) {
            $_SERVER[$key] = $original;
        } else {
            unset($_SERVER[$key]);
        }
    }
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

$test('migration contract applies and reverses schema changes', static function () use ($assertSame): void {
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

$test('database factory selects SQLite and enables foreign keys', static function () use ($assertSame): void {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        return;
    }

    $connection = Connection::fromConfig([
        'driver' => 'sqlite',
        'path' => ':memory:',
    ]);

    $assertSame(1, $connection->scalar('PRAGMA foreign_keys'));
});

$test('database factory rejects unknown drivers', static function (): void {
    try {
        Connection::fromConfig(['driver' => 'oracle']);
        throw new RuntimeException('Expected unsupported driver rejection.');
    } catch (InvalidArgumentException) {
    }
});

$test('migrations are discovered tracked and rolled back by batch', static function () use ($assertSame): void {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        return;
    }

    $connection = Connection::fromConfig(['driver' => 'sqlite', 'path' => ':memory:']);
    $repository = new MigrationRepository($connection, 'test_migrations');
    $migrator = new Migrator($connection, $repository);
    $migrations = (new MigrationFinder())->discover(__DIR__ . '/fixtures/migrations');

    $assertSame([
        '2026_01_01_000001_create_alpha',
        '2026_01_01_000002_create_beta',
    ], array_map(static fn ($file): string => $file->name, $migrations));
    $assertSame(['Pending', 'Pending'], array_column($migrator->status($migrations), 'status'));

    $assertSame([
        '2026_01_01_000001_create_alpha',
        '2026_01_01_000002_create_beta',
    ], $migrator->migrate($migrations));
    $assertSame([], $migrator->migrate($migrations));
    $assertSame(['Ran', 'Ran'], array_column($migrator->status($migrations), 'status'));
    $assertSame([1, 1], array_column($migrator->status($migrations), 'batch'));
    $assertSame(
        ['Ran', 'Missing'],
        array_column($migrator->status([$migrations[0]]), 'status'),
    );

    $assertSame([
        '2026_01_01_000002_create_beta',
        '2026_01_01_000001_create_alpha',
    ], $migrator->rollbackLast($migrations));
    $assertSame([], $repository->records());
});

$test('failed migrations are not written to history', static function () use ($assertSame): void {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        return;
    }

    $connection = Connection::fromConfig(['driver' => 'sqlite', 'path' => ':memory:']);
    $repository = new MigrationRepository($connection, 'failed_migrations');
    $migrator = new Migrator($connection, $repository);
    $migration = new class implements Migration {
        public function up(Connection $connection): void
        {
            throw new RuntimeException('Migration failed.');
        }

        public function down(Connection $connection): void
        {
        }
    };

    try {
        $migrator->migrate([new MigrationFile('broken_migration', __FILE__, $migration)]);
        throw new RuntimeException('Expected migration failure.');
    } catch (RuntimeException $exception) {
        $assertSame('Migration failed.', $exception->getMessage());
    }

    $assertSame([], $repository->records());
});

$test('migration reset and fresh rebuild the database', static function () use ($assertSame): void {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        return;
    }

    $connection = Connection::fromConfig(['driver' => 'sqlite', 'path' => ':memory:']);
    $repository = new MigrationRepository($connection, 'rebuild_migrations');
    $migrator = new Migrator($connection, $repository);
    $migrations = (new MigrationFinder())->discover(__DIR__ . '/fixtures/migrations');

    $migrator->migrate([$migrations[0]]);
    $migrator->migrate($migrations);
    $assertSame([
        '2026_01_01_000002_create_beta',
        '2026_01_01_000001_create_alpha',
    ], $migrator->reset($migrations));
    $assertSame([], $repository->records());

    $connection->execute('CREATE TABLE unrelated_data (id INTEGER PRIMARY KEY)');
    $assertSame([
        '2026_01_01_000001_create_alpha',
        '2026_01_01_000002_create_beta',
    ], $migrator->fresh($migrations));
    $assertSame(1, $connection->scalar('PRAGMA foreign_keys'));
    $assertSame(['Ran', 'Ran'], array_column($migrator->status($migrations), 'status'));

    try {
        $connection->scalar('SELECT COUNT(*) FROM unrelated_data');
        throw new RuntimeException('Expected fresh migration to drop unrelated tables.');
    } catch (\PDOException) {
    }
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
