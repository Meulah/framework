<?php

declare(strict_types=1);

use Meulah\Auth\Authenticatable;
use Meulah\Auth\Guard;
use Meulah\Auth\InvalidAuthenticatableException;
use Meulah\Auth\NativePasswordHasher;
use Meulah\Auth\PasswordHasher;
use Meulah\Auth\PasswordHashingException;
use Meulah\Auth\RequireAuthentication;
use Meulah\Auth\RequireGuest;
use Meulah\Auth\SessionGuard;
use Meulah\Auth\UserProvider;
use Meulah\Application;
use Meulah\Authorization\AbilityNotDefinedException;
use Meulah\Authorization\Authorize;
use Meulah\Authorization\AuthorizationCallbackException;
use Meulah\Authorization\AuthorizationDefinitionException;
use Meulah\Authorization\AuthorizationException;
use Meulah\Authorization\AuthorizationGate;
use Meulah\Authorization\AuthorizationMiddlewareException;
use Meulah\Authorization\AuthorizationResult;
use Meulah\Authorization\Gate;
use Meulah\Container\BindingResolutionException;
use Meulah\Container\Container;
use Meulah\Config\Repository;
use Meulah\Console\Application as ConsoleEntrypoint;
use Meulah\Console\Command;
use Meulah\Console\CommandRegistry;
use Meulah\Console\ConsoleInputException;
use Meulah\Console\ConsoleApplication;
use Meulah\Console\Input as ConsoleInput;
use Meulah\Console\Launcher;
use Meulah\Console\Output as ConsoleOutput;
use Meulah\Console\OutputException;
use Meulah\Console\ProjectRoot;
use Meulah\Database\Connection;
use Meulah\Database\Migration;
use Meulah\Database\MigrationFinder;
use Meulah\Database\MigrationFile;
use Meulah\Database\MigrationRepository;
use Meulah\Database\Migrator;
use Meulah\Event\EventDispatcher;
use Meulah\Event\EventDispatchException;
use Meulah\Event\SynchronousEventDispatcher;
use Meulah\Exception\ExceptionHandler;
use Meulah\Http\Cookie;
use Meulah\Http\Request;
use Meulah\Http\BadRequest;
use Meulah\Http\InvalidInput;
use Meulah\Http\Middleware;
use Meulah\Http\RequestHandler;
use Meulah\Http\Response;
use Meulah\Http\ResponseException;
use Meulah\Http\ResponseInterface;
use Meulah\Http\SameSite;
use Meulah\Http\UploadedFile;
use Meulah\Http\UploadException;
use Meulah\Log\Logger;
use Meulah\Routing\MethodNotAllowed;
use Meulah\Routing\MissingRouteParameterException;
use Meulah\Routing\RouteNotFound;
use Meulah\Routing\RouteDefinitionException;
use Meulah\Routing\RouteHandlerException;
use Meulah\Routing\RouteParameters;
use Meulah\Routing\Router;
use Meulah\Routing\UrlGenerationException;
use Meulah\Security\Csrf\Csrf;
use Meulah\Security\Csrf\CsrfConfigurationException;
use Meulah\Security\Csrf\VerifyCsrfToken;
use Meulah\Session\NativeSession;
use Meulah\Session\Session;
use Meulah\Session\SessionException;
use Meulah\Session\SessionMiddleware;
use Meulah\Support\Environment;
use Meulah\Support\FrameworkVersion;
use Meulah\Validation\ValidationException;
use Meulah\Validation\ValidationRuleException;
use Tests\Fixtures\AuthenticationApplicationFixture;
use Tests\Fixtures\InMemoryUserProvider;
use Meulah\Validation\Validator;
use Meulah\View\View;
use Tests\Fixtures\CircularOne;
use Tests\Fixtures\ChildEvent;
use Tests\Fixtures\AuthorizationCallLog;
use Tests\Fixtures\FakeAuthenticatable;
use Tests\Fixtures\FakeUserProvider;
use Tests\Fixtures\EventLog;
use Tests\Fixtures\FriendlyGreeting;
use Tests\Fixtures\Greeting;
use Tests\Fixtures\GreetingController;
use Tests\Fixtures\InvokableGreetingController;
use Tests\Fixtures\MutableEvent;
use Tests\Fixtures\TestUser;
use Tests\Fixtures\ParentEvent;
use Tests\Fixtures\ScalarDependencyController;
use Tests\Fixtures\OwnsRecordAbility;
use Tests\Fixtures\SendWelcomeEmail;
use Tests\Fixtures\UserRegistered;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/EventFixtures.php';
require __DIR__ . '/fixtures/ContainerFixtures.php';
require __DIR__ . '/fixtures/AuthenticationFixtures.php';
require __DIR__ . '/fixtures/AuthenticationApplicationFixture.php';

$tests = [];

$test = static function (string $name, Closure $callback) use (&$tests): void {
    $tests[$name] = $callback;
};

require __DIR__ . '/fixtures/AuthorizationFixtures.php';
$assertSame = static function (mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "Expected %s, received %s.",
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
};

/**
 * @param list<string> $arguments
 * @param array<string, string|null> $environment
 * @return array{status: int, output: string, error: string}
 */
$runCli = static function (array $arguments, string $workingDirectory, array $environment = []): array {
    $processEnvironment = getenv();
    $processEnvironment = is_array($processEnvironment) ? $processEnvironment : [];
    unset($processEnvironment['MEULAH_APPLICATION_ROOT']);

    foreach ($environment as $key => $value) {
        if ($value === null) {
            unset($processEnvironment[$key]);
            continue;
        }

        $processEnvironment[$key] = $value;
    }

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/bin/meulah', ...$arguments],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $workingDirectory,
        $processEnvironment,
        ['bypass_shell' => true],
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the Meulah CLI test process.');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'status' => proc_close($process),
        'output' => $output === false ? '' : $output,
        'error' => $error === false ? '' : $error,
    ];
};

$sessionFactory = static function (): Session {
    return new class implements Session {
        /** @var array<string, mixed> */
        public array $data = [];
        private string $identifier = 'test-session-one';
        public bool $started = false;
        public int $closeCount = 0;
        public bool $failRegeneration = false;

        public function start(): void
        {
            $this->started = true;
        }

        public function isStarted(): bool
        {
            return $this->started;
        }


        public function id(): string
        {
            $this->start();
            return $this->identifier;
        }

        public function get(string $key, mixed $default = null): mixed
        {
            $this->start();
            return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
        }

        public function put(string $key, mixed $value): void
        {
            $this->start();
            $this->data[$key] = $value;
        }

        public function remove(string $key): void
        {
            $this->start();
            unset($this->data[$key]);
        }

        public function regenerate(): void
        {
            if ($this->failRegeneration) {
                throw new SessionException('Unable to regenerate test session.');
            }

            $this->start();
            $this->identifier = 'test-session-' . bin2hex(random_bytes(8));
        }

        public function invalidate(): void
        {
            $this->start();
            $this->data = [];
            $this->regenerate();
        }

        public function flash(string $key, mixed $value): void
        {
            $this->put($key, $value);
        }

        public function keep(string ...$keys): void
        {
            $this->start();
        }

        public function reflash(): void
        {
            $this->start();
        }

        public function close(): void
        {
            $this->started = false;
            $this->closeCount++;
        }
    };
};

$test('native sessions store regenerate invalidate and age flash data', static function () use ($assertSame): void {
    $originalSavePath = session_save_path();
    $originalName = session_name();
    $originalCookieParameters = session_get_cookie_params();
    $cookieName = 'MEULAHTESTSESSION';
    $hadOriginalCookie = array_key_exists($cookieName, $_COOKIE);
    $originalCookieValue = $hadOriginalCookie ? $_COOKIE[$cookieName] : null;
    $iniKeys = [
        'session.use_strict_mode',
        'session.use_cookies',
        'session.use_only_cookies',
        'session.use_trans_sid',
        'session.cookie_lifetime',
        'session.cookie_path',
        'session.cookie_domain',
        'session.cookie_secure',
        'session.cookie_httponly',
        'session.cookie_samesite',
    ];
    $originalIni = [];

    foreach ($iniKeys as $key) {
        $originalIni[$key] = ini_get($key);
    }

    $directory = sys_get_temp_dir() . '/meulah-session-' . bin2hex(random_bytes(8));

    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create native session test directory.');
    }

    try {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_save_path($directory);

        session_id('');
        $_COOKIE[$cookieName] = 'invalid!identifier';
        $invalidIdSession = new NativeSession(name: 'MEULAHTESTSESSION', secure: false);
        $invalidIdSession->start();
        $assertSame(false, hash_equals('invalid!identifier', $invalidIdSession->id()));
        $invalidIdSession->close();
        $assertSame('', session_id());

        session_id('');
        $_COOKIE[$cookieName] = 'attackercontrolledid123';
        $session = new NativeSession(name: 'MEULAHTESTSESSION', secure: false);
        $session->start();
        $assertSame(false, hash_equals('attackercontrolledid123', $session->id()));
        $firstId = $session->id();
        $session->start();

        $assertSame($firstId, $session->id());
        $assertSame(true, $session instanceof Session);
        $assertSame(true, $session->isStarted());
        $assertSame(true, (bool) ini_get('session.use_strict_mode'));
        $assertSame(true, (bool) ini_get('session.use_cookies'));
        $assertSame(true, (bool) ini_get('session.use_only_cookies'));
        $assertSame(false, (bool) ini_get('session.use_trans_sid'));
        $assertSame(false, (bool) session_get_cookie_params()['secure']);
        $assertSame(true, (bool) session_get_cookie_params()['httponly']);
        $assertSame('Lax', session_get_cookie_params()['samesite']);
        $session->put('user_id', 42);
        $session->put('nullable', null);
        $assertSame(42, $session->get('user_id'));
        $assertSame(null, $session->get('nullable', 'fallback'));
        $duplicate = new NativeSession(name: 'MEULAHTESTSESSION', secure: false);

        try {
            $duplicate->get('user_id');
            throw new RuntimeException('Expected duplicate native session ownership rejection.');
        } catch (SessionException $exception) {
            $assertSame(true, str_contains($exception->getMessage(), 'already managed'));
        }


        $csrf = new Csrf($session);
        $oldToken = $csrf->token();
        $oldId = $session->id();
        $session->regenerate();
        $assertSame(false, hash_equals($oldId, $session->id()));
        $assertSame(false, hash_equals($oldToken, $csrf->token()));
        $session->flash('notice', 'Saved');
        $session->flash('kept', 'Keep me');
        $session->flash('reflashed', 'Reflash me');
        $session->flash('overwritten', 'first');
        $session->flash('overwritten', 'second');
        $session->regenerate();
        $assertSame(42, $session->get('user_id'));
        $nextSessionId = $session->id();
        $session->close();
        $assertSame('', session_id());
        $_COOKIE[$cookieName] = $nextSessionId;

        $nextRequest = $session;
        $assertSame(false, $nextRequest->isStarted());
        $assertSame('Saved', $nextRequest->get('notice'));
        $assertSame('Keep me', $nextRequest->get('kept'));
        $assertSame('Reflash me', $nextRequest->get('reflashed'));
        $assertSame('second', $nextRequest->get('overwritten'));
        $assertSame(42, $nextRequest->get('user_id'));
        $nextRequest->put('notice', 'normal value');
        $nextRequest->keep('kept');
        $nextRequest->keep('missing');
        $nextRequest->reflash();
        $nextRequest->remove('overwritten');
        $followingSessionId = $nextRequest->id();
        $nextRequest->close();
        $assertSame('', session_id());
        $_COOKIE[$cookieName] = $followingSessionId;

        $followingRequest = new NativeSession(name: 'MEULAHTESTSESSION', secure: false);
        $assertSame('normal value', $followingRequest->get('notice'));
        $assertSame('Keep me', $followingRequest->get('kept'));
        $assertSame('Reflash me', $followingRequest->get('reflashed'));
        $assertSame('missing', $followingRequest->get('overwritten', 'missing'));
        $finalSessionId = $followingRequest->id();
        $followingRequest->close();
        $assertSame('', session_id());
        $_COOKIE[$cookieName] = $finalSessionId;

        $finalRequest = new NativeSession(name: 'MEULAHTESTSESSION', secure: false);
        $assertSame('normal value', $finalRequest->get('notice'));
        $assertSame('missing', $finalRequest->get('kept', 'missing'));
        $assertSame('missing', $finalRequest->get('reflashed', 'missing'));
        $finalRequest->flash('clear_on_invalidate', 'value');
        $idBeforeInvalidation = $finalRequest->id();
        $finalRequest->invalidate();
        $assertSame(false, hash_equals($idBeforeInvalidation, $finalRequest->id()));
        $assertSame('missing', $finalRequest->get('user_id', 'missing'));
        $assertSame('missing', $finalRequest->get('notice', 'missing'));
        $assertSame('missing', $finalRequest->get('clear_on_invalidate', 'missing'));
        $secondInvalidationId = $finalRequest->id();
        $finalRequest->invalidate();
        $assertSame(false, hash_equals($secondInvalidationId, $finalRequest->id()));
        $finalRequest->close();
        $assertSame('', session_id());

    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }

        session_id('');
        session_name($originalName);
        session_save_path($originalSavePath);
        session_set_cookie_params($originalCookieParameters);

        if ($hadOriginalCookie) {
            $_COOKIE[$cookieName] = $originalCookieValue;
        } else {
            unset($_COOKIE[$cookieName]);
        }

        foreach ($originalIni as $key => $value) {
            if (is_string($value)) {
                ini_set($key, $value);
            }
        }

        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});


$test('session middleware preserves lazy start and closes active sessions', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $middleware = new SessionMiddleware($session);
    $response = $middleware->process(
        new Request('GET', '/'),
        new class implements RequestHandler {
            public function handle(Request $request): ResponseInterface
            {
                return Response::html('lazy');
            }
        },
    );

    $assertSame('lazy', $response->content());
    $assertSame(false, $session->isStarted());
    $assertSame(1, $session->closeCount);

    $active = $sessionFactory();
    $activeMiddleware = new SessionMiddleware($active);
    $response = $activeMiddleware->process(
        new Request('GET', '/write'),
        new class($active) implements RequestHandler {
            public function __construct(private readonly Session $session)
            {
            }

            public function handle(Request $request): ResponseInterface
            {
                $this->session->put('value', 'stored');
                return Response::html('active');
            }
        },
    );

    $assertSame('active', $response->content());
    $assertSame('stored', $active->data['value']);
    $assertSame(false, $active->isStarted());
    $assertSame(1, $active->closeCount);
});

$test('session middleware closes sessions when downstream handling fails', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $middleware = new SessionMiddleware($session);

    try {
        $middleware->process(
            new Request('GET', '/fail'),
            new class implements RequestHandler {
                public function handle(Request $request): ResponseInterface
                {
                    throw new RuntimeException('downstream failed');
                }
            },
        );
        throw new RuntimeException('Expected downstream failure.');
    } catch (RuntimeException $exception) {
        $assertSame('downstream failed', $exception->getMessage());
    }

    $assertSame(1, $session->closeCount);
    $assertSame(false, $session->isStarted());
});
$test('session guard represents ordinary guest state without consulting the provider', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $provider = new FakeUserProvider();
    $guard = new SessionGuard($session, $provider, '_auth_user');

    $assertSame(null, $guard->user());
    $assertSame(null, $guard->id());
    $assertSame(false, $guard->check());
    $assertSame(true, $guard->guest());
    $assertSame([], $provider->retrieved);
});

$test('session guard login and logout rotate identifiers without storing user objects', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $session->put('cart', ['book']);
    $provider = new FakeUserProvider();
    $guard = new SessionGuard($session, $provider, '_auth_user');
    $user = new FakeAuthenticatable('00042');
    $beforeLogin = $session->id();

    $guard->login($user);

    $afterLogin = $session->id();
    $assertSame(false, $beforeLogin === $afterLogin);
    $assertSame('00042', $session->data['_auth_user']);
    $assertSame(false, $session->data['_auth_user'] instanceof Authenticatable);
    $assertSame($user, $guard->user());
    $assertSame('00042', $guard->id());
    $assertSame(true, $guard->check());
    $assertSame(false, $guard->guest());
    $assertSame(['book'], $session->data['cart']);
    $assertSame([], $provider->retrieved);

    $guard->logout();

    $assertSame(false, $afterLogin === $session->id());
    $assertSame(false, array_key_exists('_auth_user', $session->data));
    $assertSame(['book'], $session->data['cart']);
    $assertSame(null, $guard->user());
    $assertSame(null, $guard->id());
    $assertSame(true, $guard->guest());
});

$test('session guard clears cached identity when logout rotation fails', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $guard = new SessionGuard($session, new FakeUserProvider(), '_auth_user');
    $user = new FakeAuthenticatable('rotation-user');
    $guard->login($user);
    $assertSame($user, $guard->user());

    $session->failRegeneration = true;

    try {
        $guard->logout();
        throw new RuntimeException('Expected logout rotation failure.');
    } catch (SessionException $exception) {
        $assertSame('Unable to regenerate test session.', $exception->getMessage());
    }

    $session->failRegeneration = false;
    $assertSame(false, array_key_exists('_auth_user', $session->data));
    $assertSame(null, $guard->user());
    $assertSame(true, $guard->guest());
});

$test('session guard restores exact edge-case string identifiers and refreshes across session lifecycle changes', static function () use ($assertSame, $sessionFactory): void {
    foreach (['0', '00042', '??:?', ' spaced '] as $identifier) {
        $session = $sessionFactory();
        $provider = new FakeUserProvider();
        $user = new FakeAuthenticatable($identifier);
        $provider->add($user);
        $session->put('_auth_user', $identifier);
        $guard = new SessionGuard($session, $provider, '_auth_user');

        $assertSame($user, $guard->user());
        $assertSame($identifier, $guard->id());
        $assertSame([$identifier], $provider->retrieved);
        $assertSame($user, $guard->user());
        $assertSame([$identifier], $provider->retrieved);

        $session->regenerate();
        $assertSame($user, $guard->user());
        $assertSame([$identifier, $identifier], $provider->retrieved);

        $session->invalidate();
        $assertSame(null, $guard->user());
        $assertSame(null, $guard->id());
        $assertSame([$identifier, $identifier], $provider->retrieved);
    }
});

$test('session guard rejects malformed stored and application identifiers without scalar guessing', static function () use ($assertSame, $sessionFactory): void {
    foreach ([null, 0, false, '', []] as $identifier) {
        $session = $sessionFactory();
        $session->put('_auth_user', $identifier);
        $provider = new FakeUserProvider();
        $guard = new SessionGuard($session, $provider, '_auth_user');

        try {
            $guard->user();
            throw new RuntimeException('Expected malformed stored identifier rejection.');
        } catch (InvalidAuthenticatableException $exception) {
            $assertSame(
                'Stored authentication identifiers must be non-empty strings.',
                $exception->getMessage(),
            );
        }

        $assertSame([], $provider->retrieved);
    }

    try {
        (new SessionGuard($sessionFactory(), new FakeUserProvider(), '_auth_user'))
            ->login(new FakeAuthenticatable(''));
        throw new RuntimeException('Expected empty application identifier rejection.');
    } catch (InvalidAuthenticatableException $exception) {
        $assertSame(
            'Authenticatable authentication identifiers must be non-empty strings.',
            $exception->getMessage(),
        );
    }
});

$test('session guard treats missing users as guests and rejects provider identifier mismatches', static function () use ($assertSame, $sessionFactory): void {
    $missingSession = $sessionFactory();
    $missingSession->put('_auth_user', 'deleted-user');
    $missingProvider = new FakeUserProvider();
    $missingGuard = new SessionGuard($missingSession, $missingProvider, '_auth_user');

    $assertSame(null, $missingGuard->user());
    $assertSame(null, $missingGuard->id());
    $assertSame(true, $missingGuard->guest());
    $assertSame(['deleted-user'], $missingProvider->retrieved);
    $assertSame('deleted-user', $missingSession->data['_auth_user']);

    $mismatchSession = $sessionFactory();
    $mismatchSession->put('_auth_user', 'secret-expected-91');
    $mismatchProvider = new FakeUserProvider();
    $mismatchProvider->add(new FakeAuthenticatable('secret-actual-27'), 'secret-expected-91');
    $mismatchGuard = new SessionGuard($mismatchSession, $mismatchProvider, '_auth_user');

    try {
        $mismatchGuard->user();
        throw new RuntimeException('Expected mismatched provider identity rejection.');
    } catch (InvalidAuthenticatableException $exception) {
        $assertSame(
            'The user provider returned an Authenticatable with a different authentication identifier.',
            $exception->getMessage(),
        );
        $assertSame(false, str_contains($exception->getMessage(), 'secret-expected-91'));
        $assertSame(false, str_contains($exception->getMessage(), 'secret-actual-27'));
    }
});

$test('session guard requires and isolates an explicit session key', static function () use ($assertSame, $sessionFactory): void {
    try {
        new SessionGuard($sessionFactory(), new FakeUserProvider(), '');
        throw new RuntimeException('Expected empty authentication session key rejection.');
    } catch (InvalidArgumentException $exception) {
        $assertSame('The authentication session key cannot be empty.', $exception->getMessage());
    }

    $session = $sessionFactory();
    $guard = new SessionGuard($session, new FakeUserProvider(), '_custom_auth');
    $guard->login(new FakeAuthenticatable('custom-user'));

    $assertSame('custom-user', $session->data['_custom_auth']);
    $assertSame(false, array_key_exists('_auth_user', $session->data));
});

$test('session guard handles repeated login switching users and external invalidation', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $session->put('intended', 'preserved');
    $guard = new SessionGuard($session, new FakeUserProvider(), '_auth_user');
    $alice = new FakeAuthenticatable('alice');
    $bob = new FakeAuthenticatable('bob');

    $guard->login($alice);
    $afterAlice = $session->id();
    $guard->login($alice);
    $afterRepeatedLogin = $session->id();

    $assertSame(false, $afterAlice === $afterRepeatedLogin);
    $assertSame($alice, $guard->user());
    $assertSame('preserved', $session->data['intended']);

    $guard->login($bob);

    $assertSame(false, $afterRepeatedLogin === $session->id());
    $assertSame($bob, $guard->user());
    $assertSame('bob', $guard->id());
    $assertSame('bob', $session->data['_auth_user']);
    $assertSame('preserved', $session->data['intended']);

    $session->invalidate();

    $assertSame(null, $guard->user());
    $assertSame(null, $guard->id());
    $assertSame(true, $guard->guest());
});

$test('session guard propagates provider failures without caching an identity', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $session->put('_auth_user', 'provider-user');
    $provider = new class implements UserProvider {
        public int $calls = 0;

        public function retrieveById(string $identifier): ?Authenticatable
        {
            $this->calls++;
            throw new DomainException('identity store unavailable');
        }
    };
    $guard = new SessionGuard($session, $provider, '_auth_user');

    foreach (['user', 'id'] as $method) {
        try {
            $guard->{$method}();
            throw new RuntimeException('Expected provider failure.');
        } catch (DomainException $exception) {
            $assertSame('identity store unavailable', $exception->getMessage());
        }
    }

    $assertSame(2, $provider->calls);
});

$test('session guard login and logout invalidate prior CSRF tokens', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $csrf = new Csrf($session);
    $guard = new SessionGuard($session, new FakeUserProvider(), '_auth_user');
    $beforeLogin = $csrf->token();

    $guard->login(new FakeAuthenticatable('csrf-user'));
    $afterLogin = $csrf->token();

    $assertSame(false, $csrf->isValid($beforeLogin));
    $assertSame(false, hash_equals($beforeLogin, $afterLogin));
    $assertSame(true, $csrf->isValid($afterLogin));

    $guard->logout();
    $afterLogout = $csrf->token();

    $assertSame(false, $csrf->isValid($afterLogin));
    $assertSame(false, hash_equals($afterLogin, $afterLogout));
    $assertSame(true, $csrf->isValid($afterLogout));
});

$test('authentication contracts compose through transient container bindings', static function () use ($assertSame, $sessionFactory): void {
    $container = new Container();
    $session = $sessionFactory();
    $provider = new FakeUserProvider();
    $user = new FakeAuthenticatable('container-user');
    $provider->add($user);
    $container->instance(Session::class, $session);
    $container->instance(UserProvider::class, $provider);
    $container->bind(
        Guard::class,
        static fn (Container $container): Guard => new SessionGuard(
            $container->get(Session::class),
            $container->get(UserProvider::class),
            '_auth_user',
        ),
    );

    $guard = $container->get(Guard::class);
    $assertSame(true, $guard instanceof SessionGuard);
    $assertSame(false, $guard === $container->get(Guard::class));
    $guard->login($user);
    $assertSame('container-user', $guard->id());
    $assertSame($user, $container->get(Guard::class)->user());

    $unconfigured = new Container();
    $unconfigured->instance(Session::class, $sessionFactory());
    $unconfigured->bind(
        Guard::class,
        static fn (Container $container): Guard => new SessionGuard(
            $container->get(Session::class),
            $container->get(UserProvider::class),
            '_auth_user',
        ),
    );

    try {
        $unconfigured->get(Guard::class);
        throw new RuntimeException('Expected missing user provider binding failure.');
    } catch (BindingResolutionException $exception) {
        $assertSame(true, str_contains($exception->getMessage(), UserProvider::class));
    }

    try {
        $container->get(Authenticatable::class);
        throw new RuntimeException('Expected application identity binding requirement.');
    } catch (BindingResolutionException $exception) {
        $assertSame(true, str_contains($exception->getMessage(), 'register an explicit binding'));
    }
});


$test('native password hashing preserves exact plaintext and uses unique salts', static function () use ($assertSame): void {
    $hasher = new NativePasswordHasher(PASSWORD_DEFAULT, ['cost' => 4]);
    $passwords = [
        '',
        'correct horse battery staple',
        'p?ssw?rd-??',
        ' padded password ',
        str_repeat('long-password-', 400),
    ];

    foreach ($passwords as $plainText) {
        $hash = $hasher->hash($plainText);

        $assertSame(true, $hash !== '');
        $assertSame(true, $hasher->verify($plainText, $hash));
        $assertSame(false, $hasher->verify('different-' . $plainText, $hash));
        $assertSame(false, $hasher->needsRehash($hash));
    }

    $first = $hasher->hash('same password');
    $second = $hasher->hash('same password');

    $assertSame(false, hash_equals($first, $second));
    $assertSame(false, $hasher->verify('padded password', $hasher->hash(' padded password ')));
});

$test('native password hashing handles malformed hashes and rehash requirements predictably', static function () use ($assertSame): void {
    $current = new NativePasswordHasher(PASSWORD_DEFAULT, ['cost' => 4]);
    $stronger = new NativePasswordHasher(PASSWORD_DEFAULT, ['cost' => 5]);
    $hash = $current->hash('rehash me');

    $assertSame(false, $current->needsRehash($hash));
    $assertSame(true, $stronger->needsRehash($hash));

    foreach (['', 'not-a-password-hash', '$2y$broken'] as $malformed) {
        $assertSame(false, $current->verify('password', $malformed));
        $assertSame(true, $current->needsRehash($malformed));
    }
});

$test('native password hashing rejects unsupported algorithms and malformed options', static function () use ($assertSame): void {
    $secret = 'plaintext-must-never-appear';

    try {
        new NativePasswordHasher($secret);
        throw new RuntimeException('Expected unsupported password algorithm rejection.');
    } catch (InvalidArgumentException $exception) {
        $assertSame('Unsupported password hashing algorithm.', $exception->getMessage());
        $assertSame(false, str_contains($exception->getMessage(), $secret));
    }

    foreach ([
        ['salt' => 'manual-salts-are-forbidden'],
        ['cost' => '4'],
        ['cost' => 3],
        ['cost' => 32],
        [4],
    ] as $options) {
        try {
            new NativePasswordHasher(PASSWORD_DEFAULT, $options);
            throw new RuntimeException('Expected malformed password option rejection.');
        } catch (InvalidArgumentException $exception) {
            $assertSame(false, str_contains($exception->getMessage(), 'manual-salts-are-forbidden'));
        }
    }

    $hasher = new NativePasswordHasher(PASSWORD_DEFAULT, ['cost' => 4]);
    $plainText = "secret-value\0must-not-leak";

    try {
        $hash = $hasher->hash($plainText);
        $assertSame(true, $hasher->verify($plainText, $hash));
    } catch (PasswordHashingException $exception) {
        $assertSame('Unable to hash the supplied password.', $exception->getMessage());
        $assertSame(false, str_contains($exception->getMessage(), 'must-not-leak'));
    }
});

$test('native password hashing supports Argon2id when PHP provides it', static function () use ($assertSame): void {
    if (!defined('PASSWORD_ARGON2ID')) {
        return;
    }

    $algorithm = constant('PASSWORD_ARGON2ID');

    if (!in_array($algorithm, password_algos(), true)) {
        return;
    }

    $options = ['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1];
    $hasher = new NativePasswordHasher($algorithm, $options);
    $hash = $hasher->hash('argon password');

    $assertSame(true, $hasher->verify('argon password', $hash));
    $assertSame(false, $hasher->verify('wrong password', $hash));
    $assertSame(false, $hasher->needsRehash($hash));
    $assertSame(
        true,
        (new NativePasswordHasher($algorithm, [...$options, 'time_cost' => 2]))->needsRehash($hash),
    );

    try {
        new NativePasswordHasher($algorithm, ['memory_cost' => 7]);
        throw new RuntimeException('Expected invalid Argon2id option rejection.');
    } catch (InvalidArgumentException $exception) {
        $assertSame(
            'Password hashing options must be integers within their supported range.',
            $exception->getMessage(),
        );
    }
});

$test('password hasher is safely shared through the container', static function () use ($assertSame): void {
    $container = new Container();
    $container->singleton(
        PasswordHasher::class,
        static fn (): PasswordHasher => new NativePasswordHasher(PASSWORD_DEFAULT, ['cost' => 4]),
    );

    $hasher = $container->get(PasswordHasher::class);

    $assertSame($hasher, $container->get(PasswordHasher::class));
    $assertSame(true, $hasher->verify('container password', $hasher->hash('container password')));
});

$test('authentication middleware allows a resolved user without repeated hydration', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $provider = new FakeUserProvider();
    $user = new FakeAuthenticatable('account-user');
    $provider->add($user);
    $session->put('_auth_user', 'account-user');
    $guard = new SessionGuard($session, $provider, '_auth_user');
    $handlerCalls = 0;
    $middleware = new RequireAuthentication(
        $guard,
        static function (): ResponseInterface {
            throw new RuntimeException('Authenticated request used the rejection callback.');
        },
    );
    $router = new Router();

    $router->group(['middleware' => [$middleware]], static function (Router $router) use (&$handlerCalls): void {
        $router->get('/account', static function (Request $request) use (&$handlerCalls): string {
            $handlerCalls++;
            return $request->string('section');
        });
    });

    $request = new Request('GET', '/account', ['section' => 'profile']);
    $response = $router->dispatch($request);

    $assertSame('profile', $response->content());
    $assertSame(1, $handlerCalls);
    $assertSame(['account-user'], $provider->retrieved);
    $assertSame(['section' => 'profile'], $request->allInput());
});

$test('authentication middleware uses explicit browser and API responses', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $guard = new SessionGuard($session, new FakeUserProvider(), '_auth_user');
    $handlerCalls = 0;
    $browser = new RequireAuthentication(
        $guard,
        static function (Request $request): ResponseInterface {
            return Response::redirect('/sign-in?next=' . rawurlencode($request->path()));
        },
    );
    $browserRouter = new Router();
    $browserRouter->get('/private', static function () use (&$handlerCalls): string {
        $handlerCalls++;
        return 'private';
    })->middleware($browser);

    $browserResponse = $browserRouter->dispatch(new Request(
        'GET',
        '/private',
        headers: ['Accept' => 'application/json'],
    ));

    $assertSame(302, $browserResponse->status());
    $assertSame('/sign-in?next=%2Fprivate', $browserResponse->headers()['Location']);
    $assertSame(0, $handlerCalls);

    $api = new RequireAuthentication(
        $guard,
        static fn (): ResponseInterface => Response::json(
            ['error' => ['code' => 'unauthenticated']],
            401,
        ),
    );
    $apiRouter = new Router();
    $apiRouter->get('/api/private', static fn (): string => 'private')->middleware($api);
    $apiResponse = $apiRouter->dispatch(new Request(
        'GET',
        '/api/private',
        headers: ['Content-Type' => 'application/json'],
        rawBody: '{}',
    ));

    $assertSame(401, $apiResponse->status());
    $assertSame('application/json; charset=UTF-8', $apiResponse->headers()['Content-Type']);
    $assertSame(
        ['error' => ['code' => 'unauthenticated']],
        json_decode($apiResponse->content(), true, 512, JSON_THROW_ON_ERROR),
    );
});

$test('authentication middleware treats stale users as guests without removing identifiers', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $session->put('_auth_user', 'deleted-user');
    $provider = new FakeUserProvider();
    $guard = new SessionGuard($session, $provider, '_auth_user');
    $middleware = new RequireAuthentication(
        $guard,
        static fn (): ResponseInterface => new Response('', 401),
    );
    $router = new Router();
    $router->get('/account', static fn (): string => 'account')->middleware($middleware);

    $response = $router->dispatch(new Request('GET', '/account'));

    $assertSame(401, $response->status());
    $assertSame(['deleted-user'], $provider->retrieved);
    $assertSame('deleted-user', $session->data['_auth_user']);
});

$test('guest middleware allows guests and explicitly redirects authenticated users', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $guard = new SessionGuard($session, new FakeUserProvider(), '_auth_user');
    $middleware = new RequireGuest(
        $guard,
        static fn (): ResponseInterface => Response::redirect('/welcome'),
    );
    $router = new Router();
    $handlerCalls = 0;
    $router->get('/sign-up', static function () use (&$handlerCalls): string {
        $handlerCalls++;
        return 'sign up';
    })->middleware($middleware);

    $guestResponse = $router->dispatch(new Request('GET', '/sign-up'));
    $guard->login(new FakeAuthenticatable('existing-user'));
    $authenticatedResponse = $router->dispatch(new Request('GET', '/sign-up'));

    $assertSame('sign up', $guestResponse->content());
    $assertSame(302, $authenticatedResponse->status());
    $assertSame('/welcome', $authenticatedResponse->headers()['Location']);
    $assertSame(1, $handlerCalls);
});

$test('authentication middleware propagates provider failures', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $session->put('_auth_user', 'provider-user');
    $provider = new class implements UserProvider {
        public function retrieveById(string $identifier): ?Authenticatable
        {
            throw new DomainException('identity provider unavailable');
        }
    };
    $callbackCalled = false;
    $middleware = new RequireAuthentication(
        new SessionGuard($session, $provider, '_auth_user'),
        static function () use (&$callbackCalled): ResponseInterface {
            $callbackCalled = true;
            return new Response('', 401);
        },
    );
    $router = new Router();
    $router->get('/account', static fn (): string => 'account')->middleware($middleware);

    try {
        $router->dispatch(new Request('GET', '/account'));
        throw new RuntimeException('Expected identity provider failure.');
    } catch (DomainException $exception) {
        $assertSame('identity provider unavailable', $exception->getMessage());
    }

    $assertSame(false, $callbackCalled);
});

$test('authentication middleware composes through nested groups before invokable controllers', static function () use ($assertSame, $sessionFactory): void {
    $events = new ArrayObject();
    $session = $sessionFactory();
    $session->put('_auth_user', 'nested-user');
    $user = new FakeAuthenticatable('nested-user');
    $provider = new class($user, $events) implements UserProvider {
        public function __construct(
            private readonly Authenticatable $user,
            private readonly ArrayObject $events,
        ) {
        }

        public function retrieveById(string $identifier): ?Authenticatable
        {
            $this->events[] = 'auth:check';
            return $this->user;
        }
    };
    $guard = new SessionGuard($session, $provider, '_auth_user');
    $authentication = new RequireAuthentication(
        $guard,
        static fn (): ResponseInterface => new Response('', 401),
    );
    $record = static function (string $name) use ($events): Middleware {
        return new class($name, $events) implements Middleware {
            public function __construct(
                private readonly string $name,
                private readonly ArrayObject $events,
            ) {
            }

            public function process(Request $request, RequestHandler $next): ResponseInterface
            {
                $this->events[] = $this->name . ':before';
                $response = $next->handle($request);
                $this->events[] = $this->name . ':after';
                return $response;
            }
        };
    };
    $router = new Router();
    $router->container()->singleton(Greeting::class, FriendlyGreeting::class);

    $router->group([
        'prefix' => '/members',
        'middleware' => [$record('outer'), $authentication],
    ], static function (Router $router) use ($record): void {
        $router->group([
            'middleware' => [$record('inner')],
        ], static function (Router $router): void {
            $router->get('/hello', InvokableGreetingController::class);
        });
    });

    $response = $router->dispatch(new Request('GET', '/members/hello', ['name' => 'Ada']));

    $assertSame('Hello, Ada!', $response->content());
    $assertSame(
        ['outer:before', 'auth:check', 'inner:before', 'inner:after', 'outer:after'],
        (array) $events,
    );
});

$test('authentication middleware preserves HEAD semantics', static function () use ($assertSame, $sessionFactory): void {
    $middleware = new RequireAuthentication(
        new SessionGuard($sessionFactory(), new FakeUserProvider(), '_auth_user'),
        static fn (): ResponseInterface => Response::html('Not authenticated', 401),
    );
    $router = new Router();
    $router->get('/account', static fn (): string => 'secret')->middleware($middleware);

    $response = $router->dispatch(new Request('HEAD', '/account'));

    $assertSame(401, $response->status());
    $assertSame('', $response->content());
    $assertSame('text/html; charset=UTF-8', $response->headers()['Content-Type']);
});

$authorizationGateFactory = static function (
    ?Authenticatable $user = null,
    ?Container $container = null,
) use ($sessionFactory): AuthorizationGate {
    $guard = new SessionGuard($sessionFactory(), new FakeUserProvider(), '_auth_user');

    if ($user !== null) {
        $guard->login($user);
    }

    return new AuthorizationGate($guard, $container ?? new Container());
};

$test('authorization results are immutable explicit decisions', static function () use ($assertSame): void {
    $allowed = AuthorizationResult::allow();
    $denied = AuthorizationResult::deny('Record access denied.', 'record_denied');

    $assertSame(true, $allowed->allowed());
    $assertSame(false, $allowed->denied());
    $assertSame(null, $allowed->message());
    $assertSame(null, $allowed->code());
    $assertSame(false, $denied->allowed());
    $assertSame(true, $denied->denied());
    $assertSame('Record access denied.', $denied->message());
    $assertSame('record_denied', $denied->code());
});

$test('authorization gate evaluates authenticated actors and multiple arguments', static function () use ($assertSame, $authorizationGateFactory): void {
    $gate = $authorizationGateFactory(new FakeAuthenticatable('actor-42'));
    $gate->define(
        'records.update:v1',
        static function (
            Authenticatable $actor,
            string $ownerIdentifier,
            int $expectedVersion,
            int $actualVersion,
        ): bool {
            return $actor->authIdentifier() === $ownerIdentifier
                && $expectedVersion === $actualVersion;
        },
    );

    $assertSame(true, $gate->allows('records.update:v1', 'actor-42', 7, 7));
    $assertSame(false, $gate->denies('records.update:v1', 'actor-42', 7, 7));
    $assertSame(false, $gate->allows('records.update:v1', 'other', 7, 7));
    $assertSame(true, $gate->denies('records.update:v1', 'actor-42', 7, 8));
    $gate->authorize('records.update:v1', 'actor-42', 7, 7);
});

$test('authorization denial preserves safe context without exposing it automatically', static function () use ($assertSame, $authorizationGateFactory): void {
    $gate = $authorizationGateFactory(new FakeAuthenticatable('actor'));
    $denial = AuthorizationResult::deny('Sensitive business explanation.', 'ownership_failed');
    $gate->define(
        'records.delete',
        static fn (Authenticatable $actor): AuthorizationResult => $denial,
    );

    $assertSame($denial, $gate->inspect('records.delete'));

    try {
        $gate->authorize('records.delete');
        throw new RuntimeException('Expected authorization denial.');
    } catch (AuthorizationException $exception) {
        $assertSame('This action is not authorized.', $exception->getMessage());
        $assertSame(false, str_contains($exception->getMessage(), 'Sensitive'));
        $assertSame('records.delete', $exception->ability());
        $assertSame($denial, $exception->result());
    }
});

$test('authorization definitions reject missing duplicate wildcard and unstable abilities', static function () use ($assertSame, $sessionFactory): void {
    $provider = new class implements UserProvider {
        public int $calls = 0;

        public function retrieveById(string $identifier): ?Authenticatable
        {
            $this->calls++;
            return new FakeAuthenticatable($identifier);
        }
    };
    $session = $sessionFactory();
    $session->put('_auth_user', 'actor');
    $gate = new AuthorizationGate(
        new SessionGuard($session, $provider, '_auth_user'),
        new Container(),
    );

    try {
        $gate->inspect('missing');
        throw new RuntimeException('Expected missing ability failure.');
    } catch (AbilityNotDefinedException $exception) {
        $assertSame("Authorization ability 'missing' is not defined.", $exception->getMessage());
    }

    $assertSame(0, $provider->calls);
    $gate->define('stable-ability', static fn (Authenticatable $actor): bool => true);

    try {
        $gate->define('stable-ability', static fn (Authenticatable $actor): bool => false);
        throw new RuntimeException('Expected duplicate ability failure.');
    } catch (AuthorizationDefinitionException $exception) {
        $assertSame(
            "Authorization ability 'stable-ability' is already defined.",
            $exception->getMessage(),
        );
    }

    $assertSame(true, $gate->allows('stable-ability'));

    foreach (['', ' leading', 'trailing ', 'records.*', '*'] as $invalidAbility) {
        try {
            $gate->define($invalidAbility, static fn (Authenticatable $actor): bool => true);
            throw new RuntimeException('Expected invalid ability name failure.');
        } catch (AuthorizationDefinitionException $exception) {
            $assertSame(
                'Authorization ability names must be non-empty exact names without whitespace or wildcards.',
                $exception->getMessage(),
            );
        }
    }
});

$test('authorization guest access is explicit in the actor parameter', static function () use ($assertSame, $authorizationGateFactory): void {
    $gate = $authorizationGateFactory();
    $authenticatedOnlyCalls = 0;
    $guestAwareCalls = 0;
    $gate->define(
        'account.view',
        static function (Authenticatable $actor) use (&$authenticatedOnlyCalls): bool {
            $authenticatedOnlyCalls++;
            return true;
        },
    );
    $gate->define(
        'article.view',
        static function (?Authenticatable $actor, bool $published) use (&$guestAwareCalls): bool {
            $guestAwareCalls++;
            return $published || $actor !== null;
        },
    );

    $assertSame(true, $gate->denies('account.view'));
    $assertSame(0, $authenticatedOnlyCalls);
    $assertSame(true, $gate->allows('article.view', true));
    $assertSame(false, $gate->allows('article.view', false));
    $assertSame(2, $guestAwareCalls);

    $authenticated = $authorizationGateFactory(new FakeAuthenticatable('reader'));
    $authenticated->define(
        'article.view',
        static fn (?Authenticatable $actor, bool $published): bool => $published || $actor !== null,
    );
    $assertSame(true, $authenticated->allows('article.view', false));
});

$test('authorization callbacks reject unsupported definitions and return values', static function () use ($assertSame, $authorizationGateFactory): void {
    $gate = $authorizationGateFactory(new FakeAuthenticatable('actor'));

    foreach ([
        'strlen',
        stdClass::class,
        static fn (): bool => true,
        static fn (string $actor): bool => true,
    ] as $index => $callback) {
        try {
            $gate->define('invalid-' . $index, $callback);
            throw new RuntimeException('Expected invalid authorization callback rejection.');
        } catch (AuthorizationDefinitionException $exception) {
            $assertSame(true, str_starts_with($exception->getMessage(), 'Authorization'));
        }
    }

    $gate->define('invalid-result', static fn (Authenticatable $actor): int => 1);

    try {
        $gate->inspect('invalid-result');
        throw new RuntimeException('Expected invalid authorization return rejection.');
    } catch (AuthorizationCallbackException $exception) {
        $assertSame(
            "Authorization ability 'invalid-result' must return bool or AuthorizationResult; int returned.",
            $exception->getMessage(),
        );
    }

    $gate->define('throws', static function (Authenticatable $actor): bool {
        throw new DomainException('authorization dependency failed');
    });

    try {
        $gate->inspect('throws');
        throw new RuntimeException('Expected authorization callback failure.');
    } catch (DomainException $exception) {
        $assertSame('authorization dependency failed', $exception->getMessage());
    }
});

$test('authorization gate resolves invokable classes through the container', static function () use ($assertSame, $authorizationGateFactory): void {
    $container = new Container();
    $log = new AuthorizationCallLog();
    $container->instance(AuthorizationCallLog::class, $log);
    $gate = $authorizationGateFactory(new FakeAuthenticatable('owner-9'), $container);
    $gate->define('record.update', OwnsRecordAbility::class);

    $assertSame(true, $gate->allows('record.update', 'owner-9', 'record-a'));
    $denied = $gate->inspect('record.update', 'other-owner', 'record-b');

    $assertSame(true, $denied->denied());
    $assertSame('The record is owned by another user.', $denied->message());
    $assertSame('not_owner', $denied->code());
    $assertSame(['record-a', 'record-b'], $log->entries);
});

$test('authorization gate does not retain actors across long-running reuse', static function () use ($assertSame, $sessionFactory, $authorizationGateFactory): void {
    $session = $sessionFactory();
    $guard = new SessionGuard($session, new FakeUserProvider(), '_auth_user');
    $gate = new AuthorizationGate($guard, new Container());
    $gate->define(
        'identity.matches',
        static fn (?Authenticatable $actor, string $expected): bool =>
            $actor?->authIdentifier() === $expected,
    );

    $assertSame(false, $gate->allows('identity.matches', 'alpha'));
    $guard->login(new FakeAuthenticatable('alpha'));
    $assertSame(true, $gate->allows('identity.matches', 'alpha'));
    $guard->login(new FakeAuthenticatable('beta'));
    $assertSame(false, $gate->allows('identity.matches', 'alpha'));
    $assertSame(true, $gate->allows('identity.matches', 'beta'));
    $session->invalidate();
    $assertSame(false, $gate->allows('identity.matches', 'beta'));

    $alpha = $authorizationGateFactory(new FakeAuthenticatable('alpha'));
    $beta = $authorizationGateFactory(new FakeAuthenticatable('beta'));
    $ability = static fn (Authenticatable $actor, string $expected): bool =>
        $actor->authIdentifier() === $expected;
    $alpha->define('identity.matches', $ability);
    $beta->define('identity.matches', $ability);

    $assertSame(true, $alpha->allows('identity.matches', 'alpha'));
    $assertSame(false, $beta->allows('identity.matches', 'alpha'));
});

$test('authorization gate composes through a transient container binding', static function () use ($assertSame, $sessionFactory): void {
    $guard = new SessionGuard($sessionFactory(), new FakeUserProvider(), '_auth_user');
    $guard->login(new FakeAuthenticatable('container-actor'));
    $container = new Container();
    $container->instance(Guard::class, $guard);
    $container->bind(Gate::class, static function (Container $container): Gate {
        $gate = new AuthorizationGate($container->get(Guard::class), $container);
        $gate->define(
            'container.check',
            static fn (Authenticatable $actor): bool =>
                $actor->authIdentifier() === 'container-actor',
        );
        return $gate;
    });

    $first = $container->get(Gate::class);
    $second = $container->get(Gate::class);

    $assertSame(true, $first instanceof AuthorizationGate);
    $assertSame(false, $first === $second);
    $assertSame(true, $first->allows('container.check'));
    $assertSame(true, $second->allows('container.check'));
});
$test('route authorization composes through nested groups in deterministic order', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $provider = new FakeUserProvider();
    $actor = new FakeAuthenticatable('actor-7');
    $provider->add($actor);
    $guard = new SessionGuard($session, $provider, '_auth_user');
    $guard->login($actor);
    $gate = new AuthorizationGate($guard, new Container());
    $trace = new class {
        /** @var list<string> */
        public array $entries = [];
    };
    $gate->define(
        'records.update',
        static function (
            Authenticatable $actor,
            string $record,
            string $method,
        ) use ($trace): bool {
            $trace->entries[] = 'authorize:' . $record . ':' . $method;
            return $actor->authIdentifier() === 'actor-7';
        },
    );
    $authorization = new Authorize(
        $gate,
        'records.update',
        static function (Request $request, RouteParameters $parameters) use ($trace): array {
            $trace->entries[] = 'arguments:' . implode(',', array_keys($parameters->all()));
            return [$parameters->require('record'), $request->method()];
        },
    );
    $wrappingMiddleware = static function (string $name) use ($trace): Middleware {
        return new class($trace, $name) implements Middleware {
            public function __construct(
                private readonly object $trace,
                private readonly string $name,
            ) {
            }

            public function process(Request $request, RequestHandler $next): ResponseInterface
            {
                $this->trace->entries[] = $this->name . ':before';
                $response = $next->handle($request);
                $this->trace->entries[] = $this->name . ':after';
                return $response;
            }
        };
    };
    $router = new Router();
    $router->group([
        'prefix' => '/admin',
        'middleware' => [
            new RequireAuthentication(
                $guard,
                static fn (Request $request): ResponseInterface =>
                    Response::html('Unauthenticated.', 401),
            ),
            $wrappingMiddleware('outer'),
        ],
    ], static function (Router $router) use ($authorization, $wrappingMiddleware, $trace): void {
        $router->group([
            'prefix' => '/records',
            'middleware' => [$authorization, $wrappingMiddleware('inner')],
        ], static function (Router $router) use ($trace): void {
            $router->get('/{record}', static function (string $record) use ($trace): string {
                $trace->entries[] = 'controller:' . $record;
                return 'record:' . $record;
            });
        });
    });

    $assertSame(
        'record:first',
        $router->dispatch(new Request('GET', '/admin/records/first'))->content(),
    );
    $assertSame(
        'record:second',
        $router->dispatch(new Request('GET', '/admin/records/second'))->content(),
    );
    $assertSame([
        'outer:before',
        'arguments:record',
        'authorize:first:GET',
        'inner:before',
        'controller:first',
        'inner:after',
        'outer:after',
        'outer:before',
        'arguments:record',
        'authorize:second:GET',
        'inner:before',
        'controller:second',
        'inner:after',
        'outer:after',
    ], $trace->entries);
});

$test('route authorization returns safe browser JSON and HEAD denials', static function () use ($assertSame, $authorizationGateFactory): void {
    $gate = $authorizationGateFactory(new FakeAuthenticatable('actor'));
    $gate->define(
        'secrets.read',
        static fn (Authenticatable $actor): AuthorizationResult =>
            AuthorizationResult::deny('Sensitive ownership details.', 'internal_rule_17'),
    );
    $authorization = new Authorize(
        $gate,
        'secrets.read',
        static fn (Request $request, RouteParameters $parameters): array => [],
    );
    $router = new Router();
    $router->get('/secret', static fn (): string => 'secret')->middleware($authorization);

    $browser = $router->dispatch(new Request('GET', '/secret'));
    $json = $router->dispatch(new Request(
        'GET',
        '/secret',
        headers: ['Accept' => 'application/problem+json'],
    ));
    $head = $router->dispatch(new Request('HEAD', '/secret'));
    $payload = json_decode($json->content(), true, 512, JSON_THROW_ON_ERROR);

    $assertSame(403, $browser->status());
    $assertSame('Forbidden.', $browser->content());
    $assertSame('text/html; charset=UTF-8', $browser->headers()['Content-Type']);
    $assertSame(false, str_contains($browser->content(), 'ownership'));
    $assertSame(403, $json->status());
    $assertSame([
        'error' => ['code' => 'forbidden', 'message' => 'Forbidden.'],
    ], $payload);
    $assertSame(false, str_contains($json->content(), 'internal_rule_17'));
    $assertSame('application/json; charset=UTF-8', $json->headers()['Content-Type']);
    $assertSame(403, $head->status());
    $assertSame('', $head->content());

    $custom = new Router();
    $custom->get('/custom', static fn (): string => 'secret')->middleware(new Authorize(
        $gate,
        'secrets.read',
        static fn (Request $request, RouteParameters $parameters): array => [],
        static fn (Request $request, AuthorizationResult $result): ResponseInterface =>
            Response::json(['error' => ['code' => $result->code()]], 403),
    ));
    $customPayload = json_decode(
        $custom->dispatch(new Request('GET', '/custom'))->content(),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $assertSame(['error' => ['code' => 'internal_rule_17']], $customPayload);
});

$test('authentication middleware distinguishes guests before route authorization', static function () use ($assertSame, $sessionFactory): void {
    $guard = new SessionGuard($sessionFactory(), new FakeUserProvider(), '_auth_user');
    $gate = new AuthorizationGate($guard, new Container());
    $abilityCalls = 0;
    $argumentCalls = 0;
    $gate->define(
        'account.view',
        static function (Authenticatable $actor) use (&$abilityCalls): bool {
            $abilityCalls++;
            return true;
        },
    );
    $authorization = new Authorize(
        $gate,
        'account.view',
        static function (Request $request, RouteParameters $parameters) use (&$argumentCalls): array {
            $argumentCalls++;
            return [];
        },
    );
    $authentication = new RequireAuthentication(
        $guard,
        static fn (Request $request): ResponseInterface =>
            Response::json(['error' => ['code' => 'unauthenticated']], 401),
    );
    $protected = new Router();
    $protected->get('/account', static fn (): string => 'account')
        ->middleware($authentication, $authorization);

    $unauthenticated = $protected->dispatch(new Request('GET', '/account'));
    $assertSame(401, $unauthenticated->status());
    $assertSame(0, $argumentCalls);
    $assertSame(0, $abilityCalls);

    $authorizationOnly = new Router();
    $authorizationOnly->get('/account', static fn (): string => 'account')
        ->middleware($authorization);
    $forbidden = $authorizationOnly->dispatch(new Request(
        'GET',
        '/account',
        headers: ['Accept' => 'application/json'],
    ));
    $assertSame(403, $forbidden->status());
    $assertSame(1, $argumentCalls);
    $assertSame(0, $abilityCalls);
});

$test('route authorization reports configuration and Gate failures clearly', static function () use ($assertSame, $authorizationGateFactory): void {
    $gate = $authorizationGateFactory(new FakeAuthenticatable('actor'));
    $gate->define('configured', static fn (Authenticatable $actor): bool => true);

    $missingParameter = new Router();
    $missingParameter->get('/records', static fn (): string => 'records')->middleware(new Authorize(
        $gate,
        'configured',
        static fn (Request $request, RouteParameters $parameters): array => [
            $parameters->require('record'),
        ],
    ));

    try {
        $missingParameter->dispatch(new Request('GET', '/records'));
        throw new RuntimeException('Expected missing route parameter failure.');
    } catch (MissingRouteParameterException $exception) {
        $assertSame(
            "Route parameter 'record' is not available for this request.",
            $exception->getMessage(),
        );
    }

    $missingAbility = new Router();
    $missingAbility->get('/missing', static fn (): string => 'missing')->middleware(new Authorize(
        $gate,
        'not-defined',
        static fn (Request $request, RouteParameters $parameters): array => [],
    ));

    try {
        $missingAbility->dispatch(new Request('GET', '/missing'));
        throw new RuntimeException('Expected missing ability failure.');
    } catch (AbilityNotDefinedException $exception) {
        $assertSame(
            "Authorization ability 'not-defined' is not defined.",
            $exception->getMessage(),
        );
    }

    foreach ([
        static fn (Request $request, RouteParameters $parameters): string => 'record',
        static fn (Request $request, RouteParameters $parameters): array => ['record' => 'one'],
    ] as $resolver) {
        $invalidArguments = new Router();
        $invalidArguments->get('/invalid', static fn (): string => 'invalid')
            ->middleware(new Authorize($gate, 'configured', $resolver));

        try {
            $invalidArguments->dispatch(new Request('GET', '/invalid'));
            throw new RuntimeException('Expected invalid authorization arguments.');
        } catch (AuthorizationMiddlewareException $exception) {
            $assertSame(
                'The authorization argument resolver must return a list.',
                $exception->getMessage(),
            );
        }
    }

    $gate->define('throws', static function (Authenticatable $actor): bool {
        throw new DomainException('authorization service unavailable');
    });
    $throws = new Router();
    $throws->get('/throws', static fn (): string => 'throws')->middleware(new Authorize(
        $gate,
        'throws',
        static fn (Request $request, RouteParameters $parameters): array => [],
    ));

    try {
        $throws->dispatch(new Request('GET', '/throws'));
        throw new RuntimeException('Expected Gate exception propagation.');
    } catch (DomainException $exception) {
        $assertSame('authorization service unavailable', $exception->getMessage());
    }

    try {
        new Authorize(
            $gate,
            ' invalid ',
            static fn (Request $request, RouteParameters $parameters): array => [],
        );
        throw new RuntimeException('Expected invalid middleware ability rejection.');
    } catch (AuthorizationMiddlewareException $exception) {
        $assertSame(
            'Authorization middleware requires a valid exact ability name.',
            $exception->getMessage(),
        );
    }
});

$test('route authorization evaluates spoofed effective methods explicitly', static function () use ($assertSame, $authorizationGateFactory): void {
    $gate = $authorizationGateFactory(new FakeAuthenticatable('actor'));
    $gate->define(
        'records.patch',
        static fn (
            Authenticatable $actor,
            string $record,
            string $method,
            string $originalMethod,
        ): bool => $record === '42' && $method === 'PATCH' && $originalMethod === 'POST',
    );
    $router = new Router();
    $router->patch('/records/{record}', static fn (Request $request, string $record): string =>
        $request->originalMethod() . ':' . $request->method() . ':' . $record)
        ->middleware(new Authorize(
            $gate,
            'records.patch',
            static fn (Request $request, RouteParameters $parameters): array => [
                $parameters->require('record'),
                $request->method(),
                $request->originalMethod(),
            ],
        ));

    $response = $router->dispatch(new Request(
        'POST',
        '/records/42',
        body: ['_method' => 'patch'],
    ));

    $assertSame(200, $response->status());
    $assertSame('POST:PATCH:42', $response->content());
});

$decodeIntegrationJson = static function (ResponseInterface $response): array {
    $decoded = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new RuntimeException('Expected an integration JSON object.');
    }

    return $decoded;
};

$test('authentication application fixture completes the secure login authorization and logout lifecycle', static function () use ($assertSame, $decodeIntegrationJson): void {
    $plainText = 'correct horse battery staple';
    $passwords = new NativePasswordHasher();
    $users = new InMemoryUserProvider();
    $users->add(new TestUser(
        'user-1',
        'owner@example.test',
        $passwords->hash($plainText),
        ['user-1'],
    ));
    $users->add(new TestUser(
        'user-2',
        'other@example.test',
        $passwords->hash('another application password'),
        ['user-2'],
    ));
    $application = new AuthenticationApplicationFixture($users, $passwords);

    $guestAccount = $application->request('GET', '/account');
    $assertSame(401, $guestAccount->response->status());
    $assertSame(
        'unauthenticated',
        $decodeIntegrationJson($guestAccount->response)['error']['code'],
    );

    $loginPage = $application->request(
        'GET',
        '/login',
        $guestAccount->sessionIdentifier,
    );
    $assertSame(200, $loginPage->response->status());
    $assertSame($guestAccount->sessionIdentifier, $loginPage->sessionIdentifier);
    $preLoginToken = $decodeIntegrationJson($loginPage->response)['csrf_token'];

    $invalidLogin = $application->request(
        'POST',
        '/login',
        $loginPage->sessionIdentifier,
        [
            Csrf::FIELD => $preLoginToken,
            'email' => 'owner@example.test',
            'password' => 'wrong password',
        ],
    );
    $assertSame(401, $invalidLogin->response->status());
    $assertSame($loginPage->sessionIdentifier, $invalidLogin->sessionIdentifier);
    $assertSame(
        'invalid_credentials',
        $decodeIntegrationJson($invalidLogin->response)['error']['code'],
    );
    $assertSame(false, str_contains($invalidLogin->response->content(), 'wrong password'));

    $validLogin = $application->request(
        'POST',
        '/login',
        $invalidLogin->sessionIdentifier,
        [
            Csrf::FIELD => $preLoginToken,
            'email' => 'owner@example.test',
            'password' => $plainText,
        ],
    );
    $assertSame(200, $validLogin->response->status());
    $assertSame(true, $decodeIntegrationJson($validLogin->response)['authenticated']);
    $assertSame(true, $passwords->verify($plainText, $users->findByEmail('owner@example.test')->passwordHash()));
    $assertSame(false, hash_equals(
        $invalidLogin->sessionIdentifier,
        $validLogin->sessionIdentifier,
    ));
    $assertSame(false, $application->sessions->exists($invalidLogin->sessionIdentifier));
    $assertSame([
        'owner@example.test',
        'owner@example.test',
        'owner@example.test',
    ], $users->credentialLookups);

    $replayedPreLoginSession = $application->request(
        'GET',
        '/account',
        $invalidLogin->sessionIdentifier,
    );
    $assertSame(401, $replayedPreLoginSession->response->status());
    $assertSame(false, hash_equals(
        $invalidLogin->sessionIdentifier,
        $replayedPreLoginSession->sessionIdentifier,
    ));

    $stalePreLoginToken = $application->request(
        'PATCH',
        '/users/user-1',
        $validLogin->sessionIdentifier,
        [Csrf::FIELD => $preLoginToken],
    );
    $assertSame(419, $stalePreLoginToken->response->status());

    $account = $application->request(
        'GET',
        '/account',
        $validLogin->sessionIdentifier,
    );
    $accountPayload = $decodeIntegrationJson($account->response);
    $assertSame(200, $account->response->status());
    $assertSame('user-1', $accountPayload['user']);
    $assertSame(true, in_array('user-1', $users->retrievedIdentifiers, true));
    $authenticatedToken = $accountPayload['csrf_token'];
    $assertSame(false, hash_equals($preLoginToken, $authenticatedToken));

    $guestOnly = $application->request(
        'GET',
        '/login',
        $account->sessionIdentifier,
    );
    $assertSame(303, $guestOnly->response->status());
    $assertSame('/account', $guestOnly->response->headers()['Location']);

    $denied = $application->request(
        'PATCH',
        '/users/user-2',
        $account->sessionIdentifier,
        [Csrf::FIELD => $authenticatedToken],
    );
    $assertSame(403, $denied->response->status());
    $assertSame('forbidden', $decodeIntegrationJson($denied->response)['error']['code']);
    $assertSame(false, str_contains($denied->response->content(), 'Sensitive'));
    $assertSame(false, str_contains($denied->response->content(), 'test_user_cannot_edit'));

    $allowed = $application->request(
        'PATCH',
        '/users/user-1',
        $denied->sessionIdentifier,
        [Csrf::FIELD => $authenticatedToken],
    );
    $assertSame(200, $allowed->response->status());
    $assertSame('user-1', $decodeIntegrationJson($allowed->response)['edited']);

    $logout = $application->request(
        'POST',
        '/logout',
        $allowed->sessionIdentifier,
        [Csrf::FIELD => $authenticatedToken],
    );
    $assertSame(200, $logout->response->status());
    $assertSame(false, $decodeIntegrationJson($logout->response)['authenticated']);
    $assertSame(false, hash_equals(
        $allowed->sessionIdentifier,
        $logout->sessionIdentifier,
    ));
    $assertSame(false, $application->sessions->exists($allowed->sessionIdentifier));
    $assertSame(false, array_key_exists(
        '_auth_user',
        $application->sessions->data($logout->sessionIdentifier),
    ));

    $afterLogout = $application->request(
        'GET',
        '/account',
        $logout->sessionIdentifier,
    );
    $assertSame(401, $afterLogout->response->status());

    $replayedAuthenticatedSession = $application->request(
        'GET',
        '/account',
        $allowed->sessionIdentifier,
    );
    $assertSame(401, $replayedAuthenticatedSession->response->status());

    $staleAuthenticatedToken = $application->request(
        'POST',
        '/logout',
        $logout->sessionIdentifier,
        [Csrf::FIELD => $authenticatedToken],
    );
    $assertSame(419, $staleAuthenticatedToken->response->status());
});

$test('authentication integration handles stale deleted and failing providers safely', static function () use ($assertSame, $decodeIntegrationJson): void {
    $passwords = new NativePasswordHasher();
    $users = new InMemoryUserProvider();
    $user = new TestUser(
        'durable-user',
        'durable@example.test',
        $passwords->hash('durable password'),
        ['durable-user'],
    );
    $users->add($user);
    $application = new AuthenticationApplicationFixture($users, $passwords);

    $staleIdentifier = $application->sessions->create([
        '_auth_user' => 'missing-user',
    ]);
    $stale = $application->request('GET', '/account', $staleIdentifier);
    $assertSame(401, $stale->response->status());
    $assertSame($staleIdentifier, $stale->sessionIdentifier);
    $assertSame(
        'missing-user',
        $application->sessions->data($staleIdentifier)['_auth_user'],
    );

    $activeIdentifier = $application->sessions->create([
        '_auth_user' => 'durable-user',
    ]);
    $active = $application->request('GET', '/account', $activeIdentifier);
    $assertSame(200, $active->response->status());
    $assertSame('durable-user', $decodeIntegrationJson($active->response)['user']);

    $users->delete('durable-user');
    $deleted = $application->request(
        'GET',
        '/account',
        $active->sessionIdentifier,
    );
    $assertSame(401, $deleted->response->status());
    $assertSame(
        'durable-user',
        $application->sessions->data($deleted->sessionIdentifier)['_auth_user'],
    );

    $users->add($user);
    $providerSecret = 'provider failure containing a production secret';
    $users->failRetrievalWith($providerSecret);
    $failed = $application->request(
        'GET',
        '/account',
        $deleted->sessionIdentifier,
    );
    $assertSame(500, $failed->response->status());
    $assertSame(true, str_contains($failed->response->content(), 'Something went wrong.'));
    $assertSame(false, str_contains($failed->response->content(), $providerSecret));
    $assertSame(false, str_contains($failed->response->content(), $user->passwordHash()));
    $assertSame(1, count($application->logger->exceptions));
    $assertSame($providerSecret, $application->logger->exceptions[0]->getMessage());
    $users->failRetrievalWith(null);
});

$test('authentication application rebuilds request state without leaking sequential users', static function () use ($assertSame, $decodeIntegrationJson): void {
    $passwords = new NativePasswordHasher();
    $users = new InMemoryUserProvider();
    $users->add(new TestUser(
        'worker-user-a',
        'a@example.test',
        $passwords->hash('password a'),
        ['worker-user-a'],
    ));
    $users->add(new TestUser(
        'worker-user-b',
        'b@example.test',
        $passwords->hash('password b'),
        ['worker-user-b'],
    ));
    $application = new AuthenticationApplicationFixture($users, $passwords);

    $login = static function (
        string $email,
        string $password,
    ) use ($application, $assertSame, $decodeIntegrationJson): string {
        $page = $application->request('GET', '/login');
        $token = $decodeIntegrationJson($page->response)['csrf_token'];
        $authenticated = $application->request(
            'POST',
            '/login',
            $page->sessionIdentifier,
            [
                Csrf::FIELD => $token,
                'email' => $email,
                'password' => $password,
            ],
        );
        $assertSame(200, $authenticated->response->status());

        return $authenticated->sessionIdentifier;
    };

    $sessionA = $login('a@example.test', 'password a');
    $sessionB = $login('b@example.test', 'password b');
    $assertSame(false, hash_equals($sessionA, $sessionB));

    foreach ([
        [$sessionA, 'worker-user-a'],
        [$sessionB, 'worker-user-b'],
        [$sessionA, 'worker-user-a'],
        [$sessionB, 'worker-user-b'],
    ] as [$session, $expectedUser]) {
        $account = $application->request('GET', '/account', $session);
        $assertSame(200, $account->response->status());
        $assertSame($expectedUser, $decodeIntegrationJson($account->response)['user']);
    }

    $users->delete('worker-user-a');
    $deletedA = $application->request('GET', '/account', $sessionA);
    $stillB = $application->request('GET', '/account', $sessionB);
    $assertSame(401, $deletedA->response->status());
    $assertSame(200, $stillB->response->status());
    $assertSame('worker-user-b', $decodeIntegrationJson($stillB->response)['user']);
});


$test('CSRF validation does not create session state for missing tokens', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $csrf = new Csrf($session);

    $assertSame(false, $csrf->isValid(str_repeat('a', 64)));
    $assertSame([], $session->data);
});

$test('CSRF rejects malformed token shapes before session access', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $csrf = new Csrf($session);
    $invalidTokens = [
        null,
        '',
        str_repeat('a', Csrf::TOKEN_LENGTH - 1),
        str_repeat('a', Csrf::TOKEN_LENGTH + 1),
        str_repeat('a', 1_048_576),
        str_repeat('A', Csrf::TOKEN_LENGTH),
        str_repeat('g', Csrf::TOKEN_LENGTH),
        [],
        new stdClass(),
    ];

    foreach ($invalidTokens as $invalidToken) {
        $assertSame(false, $csrf->isValid($invalidToken));
    }

    $assertSame(false, $session->isStarted());
    $assertSame([], $session->data);
    $assertSame(false, $csrf->isValid(str_repeat('a', Csrf::TOKEN_LENGTH)));
    $assertSame(true, $session->isStarted());
    $assertSame([], $session->data);
});

$test('CSRF tokens are random stable and bound to the session identifier', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $csrf = new Csrf($session);
    $token = $csrf->token();

    $assertSame(Csrf::TOKEN_LENGTH, strlen($token));
    $assertSame(1, preg_match('/^[a-f0-9]{' . Csrf::TOKEN_LENGTH . '}$/D', $token));
    $assertSame($token, $csrf->token());
    $assertSame(true, $csrf->isValid($token));
    $assertSame(
        '<input type="hidden" name="_token" value="' . $token . '">',
        $csrf->field(),
    );
    $assertSame(2, count($session->data));

    $session->regenerate();
    $regeneratedToken = $csrf->token();
    $assertSame(false, hash_equals($token, $regeneratedToken));
    $assertSame(false, $csrf->isValid($token));
    $assertSame(true, $csrf->isValid($regeneratedToken));

    $session->invalidate();
    $assertSame(false, $csrf->isValid($regeneratedToken));
    $replacementToken = $csrf->token();
    $assertSame(false, hash_equals($regeneratedToken, $replacementToken));
    $assertSame(false, $csrf->isValid($regeneratedToken));
    $assertSame(true, $csrf->isValid($replacementToken));
});

$test('CSRF middleware protects unsafe methods and accepts form or header tokens', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $token = (new Csrf($session))->token();
    $router = new Router();
    $router->get('/profile', static fn (): string => 'form');
    $router->post('/profile', static fn (): string => 'posted');
    $router->patch('/profile', static fn (): string => 'patched');
    $router->delete('/profile', static fn (): string => 'deleted');
    $router->options('/profile', static fn (): string => 'options');
    $router->post('/webhooks/provider', static fn (): string => 'webhook');
    $router->post('/webhooks/provider/child', static fn (): string => 'child');
    $application = new Application($router);
    $application->middleware(new VerifyCsrfToken($session, ['/webhooks/provider']));

    $assertSame('form', $application->handle(new Request('GET', '/profile'))->content());
    $assertSame('options', $application->handle(new Request('OPTIONS', '/profile'))->content());
    $assertSame(
        'posted',
        $application->handle(new Request(
            'POST',
            '/profile',
            body: [Csrf::FIELD => $token],
        ))->content(),
    );
    $assertSame(
        'patched',
        $application->handle(new Request(
            'POST',
            '/profile',
            body: ['_method' => 'PATCH', Csrf::FIELD => $token],
        ))->content(),
    );
    $assertSame(
        'deleted',
        $application->handle(new Request(
            'DELETE',
            '/profile',
            headers: [Csrf::HEADER => $token],
        ))->content(),
    );
    $assertSame(
        'webhook',
        $application->handle(new Request('POST', '/webhooks/provider'))->content(),
    );

    $missing = $application->handle(new Request('POST', '/profile'));
    $wrongJson = $application->handle(new Request(
        'POST',
        '/profile',
        headers: ['Accept' => 'application/json', Csrf::HEADER => 'wrong'],
    ));
    $wrongPayload = json_decode($wrongJson->content(), true, 512, JSON_THROW_ON_ERROR);
    $child = $application->handle(new Request('POST', '/webhooks/provider/child'));
    $conflicting = $application->handle(new Request(
        'POST',
        '/profile',
        body: [Csrf::FIELD => $token],
        headers: [Csrf::HEADER => str_repeat('a', 64)],
    ));

    $assertSame(419, $missing->status());
    $assertSame(true, str_contains($missing->content(), 'Page expired.'));
    $assertSame(419, $wrongJson->status());
    $assertSame('csrf_token_mismatch', $wrongPayload['error']['code']);
    $assertSame(419, $child->status());
    $assertSame(419, $conflicting->status());
});

$test('CSRF token sources reject malformed values and conflicts without warnings', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $token = (new Csrf($session))->token();
    $otherToken = ($token[0] === 'a' ? 'b' : 'a') . substr($token, 1);
    $router = new Router();
    $router->post('/submit', static fn (): string => 'accepted');
    $application = new Application($router);
    $application->middleware(new VerifyCsrfToken($session));
    $warnings = [];

    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return true;
    });

    $attacks = [
        ['body' => [], 'headers' => []],
        ['body' => [Csrf::FIELD => null], 'headers' => [Csrf::HEADER => $token]],
        ['body' => [Csrf::FIELD => ''], 'headers' => []],
        ['body' => [Csrf::FIELD => '   '], 'headers' => []],
        ['body' => [Csrf::FIELD => str_repeat('a', Csrf::TOKEN_LENGTH - 1)], 'headers' => []],
        ['body' => [Csrf::FIELD => str_repeat('a', 1_048_576)], 'headers' => []],
        ['body' => [Csrf::FIELD => [$token]], 'headers' => []],
        ['body' => [Csrf::FIELD => new stdClass()], 'headers' => []],
        ['body' => [Csrf::FIELD => $token], 'headers' => [Csrf::HEADER => [$token]]],
        ['body' => [Csrf::FIELD => $token], 'headers' => [Csrf::HEADER => new stdClass()]],
        ['body' => [Csrf::FIELD => $token], 'headers' => [Csrf::HEADER => $otherToken]],
    ];

    try {
        foreach ($attacks as $attack) {
            $response = $application->handle(new Request(
                'POST',
                '/submit',
                body: $attack['body'],
                headers: $attack['headers'],
            ));

            $assertSame(419, $response->status());
            $assertSame(false, str_contains($response->content(), $token));
        }
    } finally {
        restore_error_handler();
    }

    $matching = $application->handle(new Request(
        'POST',
        '/submit',
        body: [Csrf::FIELD => $token],
        headers: [Csrf::HEADER => $token],
    ));

    $assertSame(200, $matching->status());
    $assertSame('accepted', $matching->content());
    $assertSame([], $warnings);
});
$test('CSRF protects every unsafe routed method and defines content type behavior', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $token = (new Csrf($session))->token();
    $router = new Router();
    $router->get('/resource', static fn (): string => 'read');
    $router->post('/resource', static fn (): string => 'post');
    $router->put('/resource', static fn (): string => 'put');
    $router->patch('/resource', static fn (): string => 'patch');
    $router->delete('/resource', static fn (): string => 'delete');
    $router->options('/resource', static fn (): string => 'options');
    $router->match(['PROPFIND'], '/resource', static fn (): string => 'propfind');
    $router->post('/json', static function (Request $request): string {
        $request->jsonObject();
        return 'json';
    });
    $application = new Application($router);
    $application->middleware(new VerifyCsrfToken($session));

    $accepted = [
        ['post', new Request('POST', '/resource', body: [Csrf::FIELD => $token], headers: ['Content-Type' => 'application/x-www-form-urlencoded'])],
        ['post', new Request('POST', '/resource', body: [Csrf::FIELD => $token], headers: ['Content-Type' => 'multipart/form-data; boundary=test'])],
        ['post', new Request('POST', '/resource', body: [Csrf::FIELD => $token])],
        ['put', new Request('PUT', '/resource', headers: [Csrf::HEADER => $token])],
        ['patch', new Request('PATCH', '/resource', headers: [Csrf::HEADER => $token])],
        ['delete', new Request('DELETE', '/resource', headers: [Csrf::HEADER => $token])],
        ['propfind', new Request('PROPFIND', '/resource', headers: [Csrf::HEADER => $token])],
        ['json', new Request('POST', '/json', headers: ['Content-Type' => 'application/json', Csrf::HEADER => $token], rawBody: '{}')],
    ];

    foreach ($accepted as [$content, $request]) {
        $assertSame($content, $application->handle($request)->content());
    }

    $assertSame('read', $application->handle(new Request('GET', '/resource'))->content());
    $head = $application->handle(new Request('HEAD', '/resource'));
    $assertSame(200, $head->status());
    $assertSame('', $head->content());
    $assertSame('options', $application->handle(new Request('OPTIONS', '/resource'))->content());
    $assertSame(419, $application->handle(new Request('PROPFIND', '/resource'))->status());

    $jsonBodyOnly = $application->handle(new Request(
        'POST',
        '/json',
        headers: ['Content-Type' => 'application/json'],
        rawBody: json_encode([Csrf::FIELD => $token], JSON_THROW_ON_ERROR),
    ));
    $assertSame(419, $jsonBodyOnly->status());

    $malformedWithToken = $application->handle(new Request(
        'POST',
        '/json',
        headers: ['Content-Type' => 'application/json', Csrf::HEADER => $token],
        rawBody: '{',
    ));
    $malformedPayload = json_decode($malformedWithToken->content(), true, 512, JSON_THROW_ON_ERROR);
    $assertSame(400, $malformedWithToken->status());
    $assertSame('invalid_json', $malformedPayload['error']['code']);

    $malformedWithoutToken = $application->handle(new Request(
        'POST',
        '/json',
        headers: ['Content-Type' => 'application/json'],
        rawBody: '{',
    ));
    $assertSame(419, $malformedWithoutToken->status());
});
$test('CSRF failures stay inside the exception boundary without leaking or logging tokens', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $token = (new Csrf($session))->token();
    $logger = new class implements Logger {
        public int $errors = 0;

        public function error(Throwable $exception): void
        {
            $this->errors++;
        }
    };
    $router = new Router();
    $router->post('/transfer', static fn (): string => 'transferred');
    $application = new Application(
        $router,
        exceptions: new ExceptionHandler(false, $logger),
    );
    $application->middleware(new VerifyCsrfToken($session));

    $html = $application->handle(new Request('POST', '/transfer'));
    $json = $application->handle(new Request(
        'POST',
        '/transfer',
        headers: ['Accept' => 'application/json'],
    ));
    $payload = json_decode($json->content(), true, 512, JSON_THROW_ON_ERROR);

    $assertSame(419, $html->status());
    $assertSame('<h1>419</h1><p>Page expired.</p>', $html->content());
    $assertSame(false, str_contains($html->content(), $token));
    $assertSame(419, $json->status());
    $assertSame('csrf_token_mismatch', $payload['error']['code']);
    $assertSame('CSRF token mismatch.', $payload['error']['message']);
    $assertSame(false, str_contains($json->content(), $token));
    $assertSame(0, $logger->errors);
});
$test('CSRF exclusions reject route patterns and query strings', static function () use ($sessionFactory): void {
    foreach ([
        'webhooks/provider',
        '/webhooks/*',
        '/users/{user}',
        '/callback?trusted=yes',
        '/callback%3Ftrusted=yes',
        '/callback%23fragment',
        '/webhooks/%2A',
        '/callback%',
        '/callback%2',
        '/callback%GG',
    ] as $exclusion) {
        try {
            new VerifyCsrfToken($sessionFactory(), [$exclusion]);
            throw new RuntimeException('Expected non-explicit CSRF exclusion rejection.');
        } catch (CsrfConfigurationException) {
        }
    }
});
$test('CSRF exclusions match only the normalized application-relative path', static function () use ($assertSame, $sessionFactory): void {
    $router = new Router();
    $router->post('/webhooks/provider', static fn (): string => 'excluded');
    $router->post('/webhooks//provider', static fn (): string => 'duplicate');
    $router->post('/Webhooks/provider', static fn (): string => 'case');
    $router->post('/webhooks/provider/child', static fn (): string => 'child');
    $application = new Application($router);
    $application->middleware(new VerifyCsrfToken($sessionFactory(), ['/webhooks/provider']));

    foreach ([
        '/webhooks/provider',
        '/webhooks/provider/',
        '/webhooks%2Fprovider',
        '/webhooks/%70rovider',
    ] as $path) {
        $assertSame('excluded', $application->handle(new Request('POST', $path))->content());
    }

    $assertSame(419, $application->handle(new Request('POST', '/webhooks//provider'))->status());
    $assertSame(419, $application->handle(new Request('POST', '/Webhooks/provider'))->status());
    $assertSame(419, $application->handle(new Request('POST', '/webhooks/provider/child'))->status());

    $originalGet = $_GET;
    $originalPost = $_POST;
    $originalServer = $_SERVER;

    try {
        $_GET = ['signature' => 'present'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/app/webhooks/provider/?signature=present';
        $_SERVER['SCRIPT_NAME'] = '/app/index.php';

        $captured = Request::capture();
        $assertSame('/webhooks/provider', $captured->path());
        $assertSame('excluded', $application->handle($captured)->content());
    } finally {
        $_GET = $originalGet;
        $_POST = $originalPost;
        $_SERVER = $originalServer;
    }
});

$test('request exposes normalized input', static function () use ($assertSame): void {
    $request = new Request('post', '/users/login/', ['page' => '2'], ['email' => 'dev@example.com']);

    $assertSame('POST', $request->method());
    $assertSame('/users/login', $request->path());
    $assertSame('2', $request->query('page'));
    $assertSame('dev@example.com', $request->input('email'));
});

$test('POST requests support controlled form and header method overrides', static function () use ($assertSame): void {
    $form = new Request('POST', '/users/42', body: ['_method' => ' patch ']);
    $header = new Request('post', '/users/42', headers: ['X-HTTP-Method-Override' => 'DELETE']);
    $get = new Request('GET', '/users/42', body: ['_method' => 'TRACE']);
    $query = new Request('POST', '/users/42', query: ['_method' => 'PUT']);
    $matching = new Request(
        'POST',
        '/users/42',
        body: ['_method' => ' patch '],
        headers: ['X-HTTP-Method-Override' => 'PATCH'],
    );
    $getWithOverrides = new Request(
        'GET',
        '/users/42',
        body: ['_method' => ['DELETE']],
        headers: ['X-HTTP-Method-Override' => ['DELETE']],
    );

    $assertSame('POST', $form->originalMethod());
    $assertSame('PATCH', $form->method());
    $assertSame('DELETE', $header->method());
    $assertSame('GET', $get->method());
    $assertSame('POST', $matching->originalMethod());
    $assertSame('PATCH', $matching->method());
    $assertSame('GET', $getWithOverrides->method());
    $assertSame('POST', $query->method());
});

$test('method overrides reject unsupported non-string and conflicting values', static function () use ($assertSame): void {
    foreach (
        [
            ['body' => ['_method' => 'OPTIONS'], 'headers' => []],
            ['body' => ['_method' => ['PATCH']], 'headers' => []],
            ['body' => ['_method' => 'PUT'], 'headers' => ['X-HTTP-Method-Override' => 'DELETE']],
            ['body' => ['_method' => new stdClass()], 'headers' => []],
            ['body' => ['_method' => null], 'headers' => []],
            ['body' => ['_method' => ''], 'headers' => []],
            ['body' => ['_method' => "PATCH\r\n"], 'headers' => []],
            ['body' => ['_method' => "\tPATCH"], 'headers' => []],
            ['body' => [], 'headers' => ['X-HTTP-Method-Override' => ['PATCH']]],
            ['body' => [], 'headers' => ['X-HTTP-Method-Override' => new stdClass()]],
            ['body' => [], 'headers' => ['X-HTTP-Method-Override' => 'PATCH, DELETE']],
            ['body' => [], 'headers' => ['X-HTTP-Method-Override' => "PATCH\r\n"]],
            ['body' => [], 'headers' => ['X-HTTP-Method-Override' => 'PATCH', 'x-http-method-override' => 'PATCH']],
        ] as $input
    ) {
        try {
            new Request('POST', '/', body: $input['body'], headers: $input['headers']);
            throw new RuntimeException('Expected invalid method override rejection.');
        } catch (BadRequest $exception) {
            $assertSame('invalid_method_override', $exception->errorCode());
            $assertSame(400, $exception->status());
        }
    }
});

$test('method spoofing reports and routes the effective method before CSRF verification', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $token = (new Csrf($session))->token();
    $router = new Router();
    $router->patch('/profile', static fn (Request $request): string => $request->method() . '|' . $request->originalMethod());
    $application = new Application($router);
    $application->middleware(new VerifyCsrfToken($session));

    $accepted = $application->handle(new Request(
        'POST',
        '/profile',
        body: [
            '_method' => 'PATCH',
            Csrf::FIELD => $token,
        ],
    ));
    $missing = $application->handle(new Request(
        'POST',
        '/profile',
        body: ['_method' => 'PATCH'],
    ));

    $assertSame('PATCH|POST', $accepted->content());
    $assertSame(419, $missing->status());
});
$test('captured POST requests apply method overrides before routing', static function () use ($assertSame): void {
    $originalGet = $_GET;
    $originalPost = $_POST;
    $originalServer = $_SERVER;

    try {
        $_GET = [];
        $_POST = ['_method' => 'PUT'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/articles/42';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $request = Request::capture();

        $assertSame('POST', $request->originalMethod());
        $assertSame('PUT', $request->method());
    } finally {
        $_GET = $originalGet;
        $_POST = $originalPost;
        $_SERVER = $originalServer;
    }
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

$test('requests reject invalid methods paths and rewrite values', static function () use ($assertSame): void {
    foreach ([
        ["GET\r\nX-Injected", '/', 'invalid_http_method'],
        ['GET', '/safe%0Dpath', 'invalid_request_path'],
    ] as [$method, $path, $errorCode]) {
        try {
            new Request($method, $path);
            throw new RuntimeException('Expected invalid request metadata rejection.');
        } catch (BadRequest $exception) {
            $assertSame($errorCode, $exception->errorCode());
        }
    }

    $originalGet = $_GET;
    $originalServer = $_SERVER;

    try {
        $_GET = ['url' => ['not-a-path']];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            Request::capture();
            throw new RuntimeException('Expected non-string rewrite path rejection.');
        } catch (BadRequest $exception) {
            $assertSame('invalid_request_path', $exception->errorCode());
        }

        $_GET = [];
        foreach ([
            [['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => [], 'SCRIPT_NAME' => '/index.php'], 'invalid_request_path'],
            [['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => []], 'invalid_request_path'],
            [['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php', 'CONTENT_LENGTH' => 'ten'], 'invalid_content_length'],
        ] as [$server, $errorCode]) {
            $_SERVER = $server;

            try {
                Request::capture();
                throw new RuntimeException('Expected invalid captured request metadata rejection.');
            } catch (BadRequest $exception) {
                $assertSame($errorCode, $exception->errorCode());
            }
        }
    } finally {
        $_GET = $originalGet;
        $_SERVER = $originalServer;
    }
});

$test('request exposes headers JSON cookies and files', static function () use ($assertSame): void {
    $upload = UploadedFile::forTesting(
        __DIR__ . '/fixtures/views/greeting.php',
        'avatar.png',
        'image/png',
    );
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
    $assertSame(true, $request->hasHeader('X-Trace'));
    $assertSame('abc123', $request->headers()['x-trace']);
    $assertSame($upload, $request->file('avatar'));
    $assertSame($upload, $request->files()['avatar']);
    $assertSame(true, $request->hasFile('avatar'));
    $assertSame('{"name":"json-name","active":true}', $request->rawBody());
    $assertSame(true, $request->json('active'));
    $assertSame('json-name', $request->input('name'));
    $assertSame('query', $request->input('source'));
    $assertSame('form-name', $request->form('name'));
});

$test('JSON objects override query input without merging form data', static function () use ($assertSame): void {
    $request = new Request(
        'POST',
        '/input',
        ['email' => 'query@example.com', 'page' => '2'],
        ['email' => 'form@example.com', 'form_only' => 'ignored'],
        headers: ['Content-Type' => 'application/json'],
        rawBody: '{"email":"json@example.com","profile":{"name":"Ada"}}',
    );

    $assertSame('json@example.com', $request->input('email'));
    $assertSame('2', $request->input('page'));
    $assertSame(null, $request->input('form_only'));
    $assertSame('Ada', $request->json('profile.name'));
    $assertSame($request->input(), $request->allInput());
    $assertSame(true, $request->hasInput('email'));
    $assertSame(true, $request->filled('email'));
    $assertSame(false, $request->hasInput('missing'));
});

$test('keyed JSON lookup ignores top-level lists and scalars', static function () use ($assertSame): void {
    $list = new Request('POST', '/', rawBody: '["one","two"]');
    $scalar = new Request('POST', '/', rawBody: '42');

    $assertSame(['one', 'two'], $list->json());
    $assertSame('fallback', $list->json('email', 'fallback'));
    $assertSame(42, $scalar->json());
    $assertSame('fallback', $scalar->json('email', 'fallback'));
});

$test('JSON object and array shapes remain distinct', static function () use ($assertSame): void {
    $object = new Request('POST', '/', rawBody: '{}');
    $array = new Request('POST', '/', rawBody: '[]');
    $empty = new Request('POST', '/', rawBody: '');

    $assertSame(true, $object->jsonValue() instanceof stdClass);
    $assertSame(true, $empty->jsonValue() instanceof stdClass);
    $assertSame([], $array->jsonArray());

    try {
        $array->jsonObject();
        throw new RuntimeException('Expected JSON object shape rejection.');
    } catch (BadRequest $exception) {
        $assertSame('invalid_json_shape', $exception->errorCode());
    }

    try {
        $object->jsonArray();
        throw new RuntimeException('Expected JSON array shape rejection.');
    } catch (BadRequest $exception) {
        $assertSame('invalid_json_shape', $exception->errorCode());
    }
});

$test('typed input is strict and defaults apply only when missing', static function () use ($assertSame): void {
    $request = new Request('POST', '/', body: [
        'name' => 'Ada',
        'page' => '12',
        'published' => 'false',
        'roles' => ['admin'],
        'invalid_age' => 'twelve',
        'zero' => '0',
    ]);

    $assertSame('Ada', $request->string('name'));
    $assertSame(12, $request->integer('page'));
    $assertSame(false, $request->boolean('published'));
    $assertSame(['admin'], $request->array('roles'));
    $assertSame(1, $request->integer('missing', default: 1));
    $assertSame(true, $request->filled('zero'));

    try {
        $request->integer('invalid_age', default: 18);
        throw new RuntimeException('Expected invalid integer rejection.');
    } catch (InvalidInput $exception) {
        $assertSame("Input 'invalid_age' must be an integer.", $exception->getMessage());
    }
});

$test('validation returns normalized application data without mutating its source', static function () use ($assertSame): void {
    $input = [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'email_copy' => 'ada@example.com',
        'age' => '21',
        'active' => 'false',
        'roles' => ['admin', 'editor'],
        'status' => 'active',
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
        'nickname' => null,
        'ignored' => 'not validated',
    ];
    $validator = new Validator();
    $result = $validator->validate($input, [
        'name' => ['required', 'string', 'min:2', 'max:100'],
        'email' => ['required', 'email'],
        'email_copy' => ['same:email'],
        'age' => ['nullable', 'integer', 'min:18', 'max:120'],
        'active' => ['required', 'boolean'],
        'roles' => ['present', 'array', 'between:1,3'],
        'status' => ['in:pending,active,disabled'],
        'password' => ['string', 'confirmed'],
        'nickname' => ['nullable', 'string'],
        'optional' => ['string'],
    ]);

    $assertSame(true, $result->isValid());
    $assertSame([], $result->errors());
    $assertSame(null, $result->error('email'));
    $assertSame([
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'email_copy' => 'ada@example.com',
        'age' => 21,
        'active' => false,
        'roles' => ['admin', 'editor'],
        'status' => 'active',
        'password' => 'secret123',
        'nickname' => null,
    ], $result->validated());
    $assertSame('21', $input['age']);
    $assertSame('false', $input['active']);
    $assertSame('not validated', $input['ignored']);
});

$test('validation compares typed confirmations and same fields consistently', static function () use ($assertSame): void {
    $result = (new Validator())->validate([
        'pin' => '42',
        'pin_confirmation' => '42',
        'pin_copy' => '42',
        'enabled' => 'false',
        'enabled_confirmation' => 'false',
    ], [
        'pin' => ['integer', 'confirmed', 'same:pin_copy'],
        'pin_copy' => ['integer'],
        'enabled' => ['boolean', 'confirmed'],
    ]);

    $assertSame(true, $result->isValid());
    $assertSame([
        'pin' => 42,
        'pin_copy' => 42,
        'enabled' => false,
    ], $result->validated());
});

$test('validation reports strict rule failures without loose coercion', static function () use ($assertSame): void {
    $result = (new Validator())->validate([
        'name' => '',
        'email' => 'not-an-email',
        'age' => 'twenty',
        'active' => 'yes',
        'roles' => 'admin',
        'status' => 'archived',
        'email_copy' => 'different@example.com',
        'password' => 'secret',
        'password_confirmation' => 'different',
    ], [
        'name' => ['required', 'string'],
        'email' => ['email'],
        'age' => ['integer', 'min:18'],
        'active' => ['boolean'],
        'roles' => ['array'],
        'status' => ['in:pending,active'],
        'email_copy' => ['same:email'],
        'password' => ['confirmed'],
        'token' => ['present'],
    ]);

    $assertSame(false, $result->isValid());
    $assertSame('The name field is required.', $result->error('name'));
    $assertSame('The email field must be a valid email address.', $result->error('email'));
    $assertSame('The age field must be an integer.', $result->error('age'));
    $assertSame('The active field must be a boolean.', $result->error('active'));
    $assertSame('The token field must be present.', $result->error('token'));
    $assertSame(false, array_key_exists('age', $result->validated()));
});

$test('validateOrFail returns data or throws a dedicated validation exception', static function () use ($assertSame): void {
    $validator = new Validator();
    $data = $validator->validateOrFail(
        ['email' => 'ada@example.com', 'age' => '18'],
        ['email' => ['required', 'email'], 'age' => ['integer', 'min:18']],
    );

    $assertSame(['email' => 'ada@example.com', 'age' => 18], $data);

    try {
        $validator->validateOrFail(
            ['email' => 'invalid'],
            ['email' => ['required', 'email']],
        );
        throw new RuntimeException('Expected validation exception.');
    } catch (ValidationException $exception) {
        $assertSame(422, $exception->status());
        $assertSame('validation_failed', $exception->errorCode());
        $assertSame(
            'The email field must be a valid email address.',
            $exception->error('email'),
        );
        $assertSame(false, $exception->result()->isValid());
    }
});

$test('validation rejects unknown malformed and empty rule definitions', static function () use ($assertSame): void {
    $validator = new Validator();
    $invalidRules = [
        ['email' => ['sometimes']],
        ['name' => ['between:10,2']],
        ['avatar' => ['max_size:2mb']],
        ['avatar' => ['max_size:' . str_repeat('9', 100)]],
        ['name' => ['min:' . str_repeat('9', 400)]],
        ['name' => []],
    ];

    foreach ($invalidRules as $rules) {
        try {
            $validator->validate([], $rules);
            throw new RuntimeException('Expected invalid validation rule rejection.');
        } catch (ValidationRuleException) {
        }
    }

    $invalidUtf8 = $validator->validate(['name' => "\xC3\x28"], ['name' => ['string', 'min:1']]);
    $assertSame(false, $invalidUtf8->isValid());
    $assertSame('The name field must have a value or size of at least 1.', $invalidUtf8->error('name'));

    $assertSame(true, (new Container())->get(Validator::class) instanceof Validator);
});

$test('validation checks uploaded file size and detected MIME type', static function () use ($assertSame): void {
    $source = tempnam(sys_get_temp_dir(), 'meulah-validation-upload-');

    if ($source === false) {
        throw new RuntimeException('Unable to create validation upload fixture.');
    }

    file_put_contents($source, 'plain text upload');

    try {
        $upload = UploadedFile::forTesting($source, 'notes.txt', 'image/jpeg');
        $validator = new Validator();
        $valid = $validator->validate(
            ['document' => $upload],
            ['document' => ['required', 'file', 'max_size:100', 'detected_mime:text/plain']],
        );
        $invalid = $validator->validate(
            ['document' => $upload],
            ['document' => ['file', 'max_size:1', 'detected_mime:image/png']],
        );

        $assertSame(true, $valid->isValid());
        $assertSame($upload, $valid->validated()['document']);
        $assertSame(false, $invalid->isValid());
        $assertSame(2, count($invalid->errors()['document']));
    } finally {
        if (is_file($source)) {
            unlink($source);
        }
    }
});

$test('validation exceptions render JSON field errors as HTTP 422', static function () use ($assertSame): void {
    $router = new Router();
    $router->post('/register', static function (Request $request): string {
        (new Validator())->validateOrFail(
            $request->allInput(),
            ['email' => ['required', 'email']],
        );

        return 'registered';
    });
    $application = new Application($router);
    $response = $application->handle(new Request(
        'POST',
        '/register',
        body: ['email' => 'invalid'],
        headers: ['Accept' => 'application/json'],
    ));
    $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

    $assertSame(422, $response->status());
    $assertSame('validation_failed', $payload['error']['code']);
    $assertSame(
        'The email field must be a valid email address.',
        $payload['error']['fields']['email'][0],
    );
});

$test('validation presence and nullability distinguish missing empty and falsey values', static function () use ($assertSame): void {
    $validator = new Validator();
    $result = $validator->validate([
        'null_value' => null,
        'nullable_value' => null,
        'empty_string' => '',
        'whitespace' => " \t\n",
        'zero' => 0,
        'string_zero' => '0',
        'false_value' => false,
        'empty_array' => [],
        'present_null' => null,
    ], [
        'optional_missing' => ['string'],
        'required_missing' => ['required'],
        'present_missing' => ['present'],
        'null_value' => ['string'],
        'nullable_value' => ['nullable', 'string'],
        'empty_string' => ['required', 'string'],
        'whitespace' => ['required', 'string'],
        'zero' => ['required', 'integer'],
        'string_zero' => ['required', 'integer'],
        'false_value' => ['required', 'boolean'],
        'empty_array' => ['required', 'array'],
        'present_null' => ['present', 'nullable', 'string'],
    ]);

    $assertSame(false, $result->isValid());
    $assertSame([
        'required_missing',
        'present_missing',
        'null_value',
        'empty_string',
        'empty_array',
    ], array_keys($result->errors()));
    $assertSame([
        'nullable_value' => null,
        'whitespace' => " \t\n",
        'zero' => 0,
        'string_zero' => 0,
        'false_value' => false,
        'present_null' => null,
    ], $result->validated());
    $assertSame(false, array_key_exists('optional_missing', $result->validated()));
});

$test('integer validation accepts only canonical platform integers', static function () use ($assertSame): void {
    $outsideRange = PHP_INT_SIZE === 8 ? '9223372036854775808' : '2147483648';
    $input = [
        'native' => 42,
        'positive' => '42',
        'negative' => '-42',
        'negative_zero' => '-0',
        'plus' => '+42',
        'leading_zero' => '042',
        'negative_leading_zero' => '-042',
        'decimal' => '42.0',
        'scientific' => '4.2e1',
        'whitespace' => ' 42 ',
        'hex' => '0x2A',
        'outside_range' => $outsideRange,
        'true_value' => true,
        'false_value' => false,
        'float_value' => 42.0,
        'array_value' => [42],
        'object_value' => (object) ['value' => 42],
    ];
    $rules = array_fill_keys(array_keys($input), ['integer']);
    $result = (new Validator())->validate($input, $rules);

    $assertSame([
        'native' => 42,
        'positive' => 42,
        'negative' => -42,
        'negative_zero' => 0,
    ], $result->validated());
    $assertSame([
        'plus',
        'leading_zero',
        'negative_leading_zero',
        'decimal',
        'scientific',
        'whitespace',
        'hex',
        'outside_range',
        'true_value',
        'false_value',
        'float_value',
        'array_value',
        'object_value',
    ], array_keys($result->errors()));
});

$test('boolean validation accepts only documented exact forms', static function () use ($assertSame): void {
    $input = [
        'native_true' => true,
        'native_false' => false,
        'integer_one' => 1,
        'integer_zero' => 0,
        'string_true' => 'true',
        'string_false' => 'false',
        'string_one' => '1',
        'string_zero' => '0',
        'upper_true' => 'TRUE',
        'mixed_false' => 'False',
        'whitespace' => ' true ',
        'yes' => 'yes',
        'no' => 'no',
        'on' => 'on',
        'off' => 'off',
        'float_one' => 1.0,
        'array_value' => [true],
        'object_value' => (object) ['value' => true],
    ];
    $rules = array_fill_keys(array_keys($input), ['boolean']);
    $result = (new Validator())->validate($input, $rules);

    $assertSame([
        'native_true' => true,
        'native_false' => false,
        'integer_one' => true,
        'integer_zero' => false,
        'string_true' => true,
        'string_false' => false,
        'string_one' => true,
        'string_zero' => false,
    ], $result->validated());
    $assertSame([
        'upper_true',
        'mixed_false',
        'whitespace',
        'yes',
        'no',
        'on',
        'off',
        'float_one',
        'array_value',
        'object_value',
    ], array_keys($result->errors()));
});

$test('string size validation counts Unicode code points without mbstring', static function () use ($assertSame): void {
    $accent = "\u{00E9}";
    $emoji = "\u{1F600}";
    $combining = "e\u{0301}";
    $large = str_repeat('a', 100_000);
    $result = (new Validator())->validate([
        'accent' => $accent,
        'emoji' => $emoji,
        'combining' => $combining,
        'empty' => '',
        'large' => $large,
        'float_value' => 2.0,
    ], [
        'accent' => ['string', 'between:1,1'],
        'emoji' => ['string', 'between:1,1'],
        'combining' => ['string', 'between:2,2'],
        'empty' => ['string', 'min:1'],
        'large' => ['string', 'between:100000,100000'],
        'float_value' => ['min:1'],
    ]);

    $validated = $result->validated();
    $assertSame($accent, $validated['accent']);
    $assertSame($emoji, $validated['emoji']);
    $assertSame($combining, $validated['combining']);
    $assertSame(100_000, strlen($validated['large']));
    $assertSame([
        'empty',
        'float_value',
    ], array_keys($result->errors()));
});

$test('email validation is strict and performs no string normalization', static function () use ($assertSame): void {
    $result = (new Validator())->validate([
        'preserved' => 'Ada@Example.COM',
        'malformed' => 'ada.example.com',
        'unicode_domain' => "ada@\u{00E9}xample.com",
        'leading_space' => ' ada@example.com',
        'trailing_space' => 'ada@example.com ',
        'array_value' => ['ada@example.com'],
        'object_value' => (object) ['email' => 'ada@example.com'],
    ], [
        'preserved' => ['string', 'email'],
        'malformed' => ['email'],
        'unicode_domain' => ['email'],
        'leading_space' => ['email'],
        'trailing_space' => ['email'],
        'array_value' => ['email'],
        'object_value' => ['email'],
    ]);

    $assertSame(['preserved' => 'Ada@Example.COM'], $result->validated());
    $assertSame([
        'malformed',
        'unicode_domain',
        'leading_space',
        'trailing_space',
        'array_value',
        'object_value',
    ], array_keys($result->errors()));
});

$test('array validation accepts PHP arrays without coercing iterable objects', static function () use ($assertSame): void {
    $uploadPath = tempnam(sys_get_temp_dir(), 'meulah-array-upload-');
    if ($uploadPath === false) {
        throw new RuntimeException('Unable to create array validation fixture.');
    }
    file_put_contents($uploadPath, 'upload');

    try {
        $upload = UploadedFile::forTesting($uploadPath, 'upload.txt', 'text/plain');
        $result = (new Validator())->validate([
            'indexed' => ['a', 'b'],
            'associative' => ['role' => 'admin'],
            'empty' => [],
            'nested' => [['id' => 1]],
            'object_value' => (object) ['role' => 'admin'],
            'traversable' => new ArrayIterator(['a', 'b']),
            'file_value' => $upload,
            'nested_file' => [$upload],
        ], [
            'indexed' => ['array'],
            'associative' => ['array'],
            'empty' => ['array'],
            'nested' => ['array'],
            'object_value' => ['array'],
            'traversable' => ['array'],
            'file_value' => ['array'],
            'nested_file' => ['file'],
        ]);

        $assertSame([
            'indexed' => ['a', 'b'],
            'associative' => ['role' => 'admin'],
            'empty' => [],
            'nested' => [['id' => 1]],
        ], $result->validated());
        $assertSame([
            'object_value',
            'traversable',
            'file_value',
            'nested_file',
        ], array_keys($result->errors()));
    } finally {
        if (is_file($uploadPath)) {
            unlink($uploadPath);
        }
    }
});

$test('comparison and in rules use strict normalized equality', static function () use ($assertSame): void {
    $result = (new Validator())->validate([
        'missing_same' => 'value',
        'missing_confirmation' => 'value',
        'loose_same' => '1',
        'native_integer' => 1,
        'loose_confirmation' => 1,
        'loose_confirmation_confirmation' => '1',
        'string_allowed' => '1',
        'integer_allowed' => 1,
        'integer_collision' => 1,
        'boolean_allowed' => 'false',
    ], [
        'missing_same' => ['same:not_present'],
        'missing_confirmation' => ['confirmed'],
        'loose_same' => ['same:native_integer'],
        'native_integer' => ['present'],
        'loose_confirmation' => ['confirmed'],
        'string_allowed' => ['in:1'],
        'integer_allowed' => ['integer', 'in:1'],
        'integer_collision' => ['integer', 'in:01'],
        'boolean_allowed' => ['boolean', 'in:false,true'],
    ]);

    $assertSame([
        'native_integer' => 1,
        'string_allowed' => '1',
        'integer_allowed' => 1,
        'boolean_allowed' => false,
    ], $result->validated());
    $assertSame([
        'missing_same',
        'missing_confirmation',
        'loose_same',
        'loose_confirmation',
        'integer_collision',
    ], array_keys($result->errors()));
    $assertSame(
        'The integer collision field must be one of the allowed values.',
        $result->error('integer_collision'),
    );
});

$test('validation rule parsing rejects duplicates conflicts and malformed parameters', static function () use ($assertSame): void {
    $validator = new Validator();
    $invalid = [
        ['field' => ['required', 'REQUIRED']],
        ['field' => ['integer', 'boolean']],
        ['field' => ['required', 'nullable']],
        ['field' => ['array', 'email']],
        ['field' => ['boolean', 'min:1']],
        ['field' => ['string', 'max_size:10']],
        ['field' => ['same: other']],
        ['field' => ['in:a,a']],
        ['field' => ['detected_mime:text/plain; charset=utf-8']],
        ['field' => ['detected_mime:text/plain,TEXT/PLAIN']],
        ['field' => ['min']],
        ['field' => ['between:1']],
        ['field' => ['required:yes']],
        ['field' => [123]],
        ['field' => [1 => 'required']],
        ['field' => []],
        ['field' => ['unknown']],
    ];

    foreach ($invalid as $rules) {
        try {
            $validator->validate([], $rules);
            throw new RuntimeException('Expected invalid validation rule set rejection.');
        } catch (ValidationRuleException $exception) {
            $assertSame(false, str_contains($exception->getMessage(), 'secret-value'));
        }
    }

    $valid = $validator->validate(['name' => 'Ada'], ['name' => ['ReQuIrEd', 'StRiNg']]);
    $assertSame(true, $valid->isValid());
    $assertSame(['name' => 'Ada'], $valid->validated());
});

$test('validation errors preserve field and rule order with explicit short circuits', static function () use ($assertSame): void {
    $result = (new Validator())->validate([
        'second_field' => 'x',
        'first_field' => new stdClass(),
        'required_bail' => '',
        'nullable_bail' => null,
        'normalized_order' => '12',
    ], [
        'second_field' => ['in:allowed', 'min:2', 'email'],
        'first_field' => ['email', 'min:1', 'in:allowed'],
        'required_bail' => ['required', 'string', 'min:5'],
        'nullable_bail' => ['nullable', 'string', 'min:5'],
        'normalized_order' => ['min:10', 'integer'],
    ]);

    $assertSame([
        'second_field',
        'first_field',
        'required_bail',
    ], array_keys($result->errors()));
    $assertSame([
        'The second field field must be one of the allowed values.',
        'The second field field must have a value or size of at least 2.',
        'The second field field must be a valid email address.',
    ], $result->errors()['second_field']);
    $assertSame([
        'The first field field must be a valid email address.',
        'The first field field must have a value or size of at least 1.',
        'The first field field must be one of the allowed values.',
    ], $result->errors()['first_field']);
    $assertSame(['The required bail field is required.'], $result->errors()['required_bail']);
    $assertSame([
        'nullable_bail' => null,
        'normalized_order' => 12,
    ], $result->validated());
});

$test('file validation handles lifecycle MIME and exact size boundaries safely', static function () use ($assertSame): void {
    $textPath = tempnam(sys_get_temp_dir(), 'meulah-file-text-');
    $zeroPath = tempnam(sys_get_temp_dir(), 'meulah-file-zero-');
    $movedSource = tempnam(sys_get_temp_dir(), 'meulah-file-moved-');
    $missingPath = tempnam(sys_get_temp_dir(), 'meulah-file-missing-');

    if ($textPath === false || $zeroPath === false || $movedSource === false || $missingPath === false) {
        throw new RuntimeException('Unable to create file validation fixtures.');
    }

    $movedDestination = $movedSource . '-destination';
    file_put_contents($textPath, 'hello');
    file_put_contents($zeroPath, '');
    file_put_contents($movedSource, 'moved');
    file_put_contents($missingPath, 'missing');

    try {
        $text = UploadedFile::forTesting($textPath, 'photo.jpg', 'image/jpeg');
        $zero = UploadedFile::forTesting($zeroPath, 'empty.txt', 'text/plain');
        $moved = UploadedFile::forTesting($movedSource, 'moved.txt', 'text/plain');
        $moved->moveTo($movedDestination);
        $vanished = UploadedFile::forTesting($missingPath, 'missing.txt', 'text/plain');
        unlink($missingPath);
        $invalid = UploadedFile::fromPhpUpload(
            'invalid.txt',
            'text/plain',
            '',
            UPLOAD_ERR_NO_FILE,
            0,
        );

        $result = (new Validator())->validate([
            'spoofed_mime' => $text,
            'exact_size' => $text,
            'too_small_limit' => $text,
            'zero_byte' => $zero,
            'moved' => $moved,
            'invalid_upload' => $invalid,
            'vanished' => $vanished,
            'nested' => [$text],
        ], [
            'optional_missing' => ['file'],
            'required_missing' => ['required', 'file'],
            'spoofed_mime' => ['file', 'detected_mime:text/plain'],
            'exact_size' => ['file', 'max_size:5'],
            'too_small_limit' => ['file', 'max_size:4'],
            'zero_byte' => ['file', 'max_size:0'],
            'moved' => ['file'],
            'invalid_upload' => ['file'],
            'vanished' => ['file', 'detected_mime:text/plain'],
            'nested' => ['file'],
        ]);

        $assertSame([
            'spoofed_mime' => $text,
            'exact_size' => $text,
            'zero_byte' => $zero,
        ], $result->validated());
        $assertSame([
            'required_missing',
            'too_small_limit',
            'moved',
            'invalid_upload',
            'vanished',
            'nested',
        ], array_keys($result->errors()));
        $messages = implode(' ', array_merge(...array_values($result->errors())));
        $assertSame(false, str_contains($messages, $textPath));
        $assertSame(false, str_contains($messages, $missingPath));
        $assertSame(false, str_contains($messages, 'image/jpeg'));
    } finally {
        foreach ([$textPath, $zeroPath, $movedSource, $movedDestination, $missingPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});

$test('validation never mutates source data or exposes invalid and sensitive values', static function () use ($assertSame): void {
    $object = (object) ['value' => 'unchanged'];
    $source = [
        'secret' => 'super-secret-value',
        'integer' => '12',
        'nested' => ['value' => 'original'],
        'object' => $object,
    ];
    $snapshot = serialize($source);
    $result = (new Validator())->validate($source, [
        'secret' => ['in:allowed'],
        'integer' => ['integer'],
        'nested' => ['array'],
        'object' => ['string'],
    ]);

    $assertSame($snapshot, serialize($source));
    $assertSame($object, $source['object']);
    $assertSame([
        'integer' => 12,
        'nested' => ['value' => 'original'],
    ], $result->validated());
    $messages = implode(' ', array_merge(...array_values($result->errors())));
    $assertSame(false, str_contains($messages, 'super-secret-value'));
    $assertSame(false, array_key_exists('secret', $result->validated()));
    $assertSame(false, array_key_exists('object', $result->validated()));
});

$test('validation failures render safe generic HTML and structured JSON', static function () use ($assertSame): void {
    $router = new Router();
    $router->post('/validation-safety', static function (Request $request): string {
        (new Validator())->validateOrFail(
            $request->allInput(),
            ['password' => ['in:allowed']],
        );

        return 'ok';
    });
    $application = new Application($router);
    $html = $application->handle(new Request(
        'POST',
        '/validation-safety',
        body: ['password' => 'super-secret-value'],
    ));
    $json = $application->handle(new Request(
        'POST',
        '/validation-safety',
        body: ['password' => 'super-secret-value'],
        headers: ['Accept' => 'application/json'],
    ));
    $payload = json_decode($json->content(), true, 512, JSON_THROW_ON_ERROR);

    $assertSame(422, $html->status());
    $assertSame(true, str_contains($html->content(), 'The supplied data is invalid.'));
    $assertSame(false, str_contains($html->content(), 'super-secret-value'));
    $assertSame(422, $json->status());
    $assertSame('validation_failed', $payload['error']['code']);
    $assertSame(
        'The password field must be one of the allowed values.',
        $payload['error']['fields']['password'][0],
    );
    $assertSame(false, str_contains($json->content(), 'super-secret-value'));
});
$test('request body size limits fail before parsing', static function () use ($assertSame): void {
    try {
        new Request('POST', '/', rawBody: '12345', maxBodySize: 4);
        throw new RuntimeException('Expected body size rejection.');
    } catch (\Meulah\Http\BadRequest $exception) {
        $assertSame('Request body is too large.', $exception->getMessage());
    }
});

$test('captured requests reject oversized content before reading it', static function () use ($assertSame): void {
    $originalServer = $_SERVER;

    try {
        $_SERVER['CONTENT_LENGTH'] = '100';

        try {
            Request::capture(10);
            throw new RuntimeException('Expected captured body size rejection.');
        } catch (\Meulah\Http\BadRequest $exception) {
            $assertSame('Request body is too large.', $exception->getMessage());
        }
    } finally {
        $_SERVER = $originalServer;
    }
});

$test('test uploads expose detected MIME and track move state', static function () use ($assertSame): void {
    $source = tempnam(sys_get_temp_dir(), 'meulah-upload-');

    if ($source === false) {
        throw new RuntimeException('Unable to create test upload.');
    }

    $destination = $source . '-moved.txt';
    file_put_contents($source, 'plain text upload');

    try {
        $upload = UploadedFile::forTesting($source, '../unsafe-name.txt', 'image/jpeg');

        $assertSame('../unsafe-name.txt', $upload->clientFilename());
        $assertSame('image/jpeg', $upload->clientMediaType());
        $assertSame('text/plain', $upload->detectedMediaType());
        $assertSame(true, $upload->isValid());
        $assertSame(false, $upload->hasMoved());

        $upload->moveTo($destination);
        $assertSame(true, $upload->hasMoved());
        $assertSame($destination, $upload->movedPath());
        $assertSame(false, $upload->isValid());

        try {
            $upload->moveTo($destination . '-again');
            throw new RuntimeException('Expected repeated move rejection.');
        } catch (UploadException) {
        }
    } finally {
        if (is_file($source)) {
            unlink($source);
        }
        if (is_file($destination)) {
            unlink($destination);
        }
    }
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
    $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

    $assertSame(400, $response->status());
    $assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);
    $assertSame('invalid_json', $payload['error']['code']);
    $assertSame('The request body contains malformed JSON.', $payload['error']['message']);
    $assertSame(false, array_key_exists('detail', $payload['error']));
});

$test('development JSON errors may include safe parser detail', static function () use ($assertSame): void {
    $logger = new class implements Logger {
        public function error(Throwable $exception): void
        {
        }
    };
    $handler = new ExceptionHandler(true, $logger);
    $request = new Request('POST', '/', headers: ['Accept' => 'application/json']);
    $exception = new BadRequest(
        'The request body contains malformed JSON.',
        'invalid_json',
        'Syntax error.',
    );
    $payload = json_decode(
        $handler->render($exception, $request)->content(),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $assertSame('Syntax error.', $payload['error']['detail']);
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

$test('controllers receive constructor dependencies from the container', static function () use ($assertSame): void {
    $container = new Container();
    $container->singleton(Greeting::class, FriendlyGreeting::class);
    $router = new Router($container);
    $application = new Application($router, new Repository(['app' => ['name' => 'Meulah']]));
    $router->get('/hello/{name}', [GreetingController::class, 'show']);

    $response = $application->handle(new Request('GET', '/hello/Ada'));

    $assertSame('Meulah: Hello, Ada!', $response->content());
    $assertSame($container, $application->container());
    $assertSame($router, $container->get(Router::class));
});

$test('invokable controller class strings are container resolved', static function () use ($assertSame): void {
    $container = new Container();
    $container->bind(Greeting::class, FriendlyGreeting::class);
    $router = new Router($container);
    $router->post('/hello', InvokableGreetingController::class);

    $response = $router->dispatch(new Request('POST', '/hello', body: ['name' => 'Grace']));

    $assertSame('Hello, Grace!', $response->content());
});

$test('container distinguishes transient singleton and instance lifetimes', static function () use ($assertSame): void {
    $container = new Container();
    $container->bind(FriendlyGreeting::class);
    $firstTransient = $container->get(FriendlyGreeting::class);
    $secondTransient = $container->get(FriendlyGreeting::class);
    $assertSame(false, $firstTransient === $secondTransient);

    $container->singleton(Greeting::class, static fn (Container $container): FriendlyGreeting => new FriendlyGreeting());
    $assertSame($container->get(Greeting::class), $container->get(Greeting::class));

    $known = new FriendlyGreeting();
    $container->instance(Greeting::class, $known);
    $assertSame($known, $container->get(Greeting::class));
});

$test('container rejects incompatible registered instances', static function () use ($assertSame): void {
    try {
        (new Container())->instance(Greeting::class, new stdClass());
        throw new RuntimeException('Expected incompatible instance rejection.');
    } catch (BindingResolutionException $exception) {
        $assertSame(
            "Instance for 'Tests\\Fixtures\\Greeting' has incompatible type 'stdClass'.",
            $exception->getMessage(),
        );
    }
});

$test('container rejects unresolved scalar dependencies', static function () use ($assertSame): void {
    try {
        (new Container())->get(ScalarDependencyController::class);
        throw new RuntimeException('Expected scalar dependency rejection.');
    } catch (BindingResolutionException $exception) {
        $assertSame(
            "Cannot resolve parameter '\$apiKey' (string) while constructing 'Tests\\Fixtures\\ScalarDependencyController'.",
            $exception->getMessage(),
        );
    }
});

$test('container reports circular dependency chains', static function () use ($assertSame): void {
    try {
        (new Container())->get(CircularOne::class);
        throw new RuntimeException('Expected circular dependency rejection.');
    } catch (BindingResolutionException $exception) {
        $assertSame(
            'Circular dependency detected: Tests\\Fixtures\\CircularOne -> Tests\\Fixtures\\CircularTwo -> Tests\\Fixtures\\CircularOne.',
            $exception->getMessage(),
        );
    }
});

$test('events dispatch synchronously in registration order', static function () use ($assertSame): void {
    $container = new Container();
    $log = new EventLog();
    $container->instance(EventLog::class, $log);
    $events = new SynchronousEventDispatcher($container);
    $event = new UserRegistered('Ada');
    $events->listen(UserRegistered::class, static function (UserRegistered $event) use ($log): void {
        $log->entries[] = 'before:' . $event->user;
    });
    $events->listen(UserRegistered::class, SendWelcomeEmail::class);
    $events->listen(UserRegistered::class, static function (UserRegistered $event) use ($log): void {
        $log->entries[] = 'after:' . $event->user;
    });

    $returned = $events->dispatch($event);

    $assertSame($event, $returned);
    $assertSame([
        'before:Ada',
        'welcome:Ada',
        'after:Ada',
    ], $log->entries);
});

$test('application owns and container registers one event dispatcher', static function () use ($assertSame): void {
    $application = new Application(new Router());
    $events = $application->events();

    $assertSame(true, $events instanceof EventDispatcher);
    $assertSame($events, $application->container()->get(EventDispatcher::class));
});

$test('event dispatch ignores events without exact listeners', static function () use ($assertSame): void {
    $events = new SynchronousEventDispatcher();
    $event = new UserRegistered('Grace');

    $assertSame($event, $events->dispatch($event));
});

$test('event listener failures propagate and stop synchronous dispatch', static function () use ($assertSame): void {
    $events = new SynchronousEventDispatcher();
    $calls = new ArrayObject();
    $events->listen(UserRegistered::class, static function () use ($calls): void {
        $calls[] = 'first';
    });
    $events->listen(UserRegistered::class, static function (): void {
        throw new RuntimeException('Listener failed.');
    });
    $events->listen(UserRegistered::class, static function () use ($calls): void {
        $calls[] = 'third';
    });

    try {
        $events->dispatch(new UserRegistered('Lin'));
        throw new RuntimeException('Expected listener exception propagation.');
    } catch (RuntimeException $exception) {
        $assertSame('Listener failed.', $exception->getMessage());
    }

    $assertSame(['first'], $calls->getArrayCopy());
});

$test('event registration rejects unknown events and invalid class listeners', static function () use ($assertSame): void {
    $events = new SynchronousEventDispatcher();

    foreach ([
        ['Missing\\Event', static fn (): mixed => null],
        [UserRegistered::class, stdClass::class],
        [UserRegistered::class, 'Missing\\Listener'],
        [UserRegistered::class, static fn (UserRegistered $event, string $extra): mixed => null],
    ] as [$event, $listener]) {
        try {
            $events->listen($event, $listener);
            throw new RuntimeException('Expected invalid event registration rejection.');
        } catch (InvalidArgumentException) {
        }
    }

    $event = new UserRegistered('No listeners');
    $assertSame($event, $events->dispatch($event));
});
$test('events use exact classes while allowing compatible listener parameter types', static function () use ($assertSame): void {
    $events = new SynchronousEventDispatcher();
    $calls = [];
    $events->listen(ParentEvent::class, static function (ParentEvent $event) use (&$calls): void {
        $calls[] = $event::class;
    });
    $events->listen(ChildEvent::class, static function (ParentEvent $event) use (&$calls): void {
        $calls[] = 'child-as-parent';
    });
    $events->listen(ChildEvent::class, static function (object $event) use (&$calls): void {
        $calls[] = 'child-as-object';
    });

    $child = new ChildEvent();
    $assertSame($child, $events->dispatch($child));
    $assertSame(['child-as-parent', 'child-as-object'], $calls);

    $parent = new ParentEvent();
    $assertSame($parent, $events->dispatch($parent));
    $assertSame([
        'child-as-parent',
        'child-as-object',
        ParentEvent::class,
    ], $calls);
});

$test('duplicate event listeners run once per explicit registration and may mutate events', static function () use ($assertSame): void {
    $events = new SynchronousEventDispatcher();
    $calls = 0;
    $listener = static function (MutableEvent $event) use (&$calls): string {
        $calls++;
        $event->value .= ':' . $calls;
        return 'ignored';
    };
    $events->listen(MutableEvent::class, $listener);
    $events->listen(MutableEvent::class, $listener);
    $event = new MutableEvent();

    $returned = $events->dispatch($event);

    $assertSame($event, $returned);
    $assertSame(2, $calls);
    $assertSame('initial:1:2', $event->value);
});

$test('event registration rejects incompatible and by-reference event parameters', static function () use ($assertSame): void {
    $events = new SynchronousEventDispatcher();
    $invalid = [
        static function (string $event): void {
        },
        static function (stdClass $event): void {
        },
        static function (UserRegistered &$event): void {
        },
    ];

    foreach ($invalid as $listener) {
        try {
            $events->listen(UserRegistered::class, $listener);
            throw new RuntimeException('Expected incompatible listener rejection.');
        } catch (InvalidArgumentException $exception) {
            $assertSame(true, str_contains($exception->getMessage(), 'Event listener'));
        }
    }
});

$test('nested event dispatch is ordered and circular same-object dispatch is cleared safely', static function () use ($assertSame): void {
    $events = new SynchronousEventDispatcher();
    $calls = [];
    $events->listen(stdClass::class, static function () use (&$calls): void {
        $calls[] = 'inner';
    });
    $events->listen(UserRegistered::class, static function () use ($events, &$calls): void {
        $calls[] = 'outer:before';
        $events->dispatch(new stdClass());
        $calls[] = 'outer:after';
    });

    $events->dispatch(new UserRegistered('Nested'));
    $assertSame(['outer:before', 'inner', 'outer:after'], $calls);

    $circular = new SynchronousEventDispatcher();
    $recurse = true;
    $circular->listen(UserRegistered::class, static function (UserRegistered $event) use ($circular, &$recurse): void {
        if ($recurse) {
            $recurse = false;
            $circular->dispatch($event);
        }
    });
    $event = new UserRegistered('Circular');

    try {
        $circular->dispatch($event);
        throw new RuntimeException('Expected circular dispatch rejection.');
    } catch (EventDispatchException $exception) {
        $assertSame(
            "Circular dispatch detected for event object 'Tests\\Fixtures\\UserRegistered'.",
            $exception->getMessage(),
        );
    }

    $assertSame($event, $circular->dispatch($event));
});


$test('route handler injection is strict and reports argument mismatches', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/users/{user}', static fn ($request, string $user): string => $user);

    try {
        $router->dispatch(new Request('GET', '/users/42'));
        throw new RuntimeException('Expected route argument mismatch.');
    } catch (RouteHandlerException $exception) {
        $assertSame('Route handler requires 2 arguments; 1 provided.', $exception->getMessage());
    }
});
$test('route handlers reject inaccessible methods and unsupported route parameter types with context', static function () use ($assertSame): void {
    $hidden = new class {
        private function show(): string
        {
            return 'hidden';
        }
    };
    $cases = [
        [
            '/hidden',
            [$hidden, 'show'],
            "Route handler 'class@anonymous::show' is not publicly callable.",
            '/hidden',
        ],
        [
            '/integer/{user}',
            static fn (int $user): string => (string) $user,
            "Route parameter 'user' cannot be passed to handler parameter '\$user' typed int; use string, mixed, or no type.",
            '/integer/42',
        ],
        [
            '/union/{user}',
            static fn (string|int $user): string => (string) $user,
            "Route parameter 'user' cannot be passed to handler parameter '\$user' typed string|int; use string, mixed, or no type.",
            '/union/42',
        ],
        [
            '/request-union',
            static fn (Request|string $request): string => 'ambiguous',
            'Request injection requires the first handler parameter to be typed exactly as Meulah\\Http\\Request.',
            '/request-union',
        ],
        [
            '/reference/{user}',
            static function (string &$user): string {
                return $user;
            },
            "Route parameter 'user' cannot be passed by reference to handler parameter '\$user'.",
            '/reference/42',
        ],
        [
            '/request-reference',
            static function (Request &$request): string {
                return 'request';
            },
            'The injected Request handler parameter cannot be passed by reference.',
            '/request-reference',
        ],
    ];

    foreach ($cases as $index => [$path, $handler, $message, $requestPath]) {
        $router = new Router();
        $router->get($path, $handler);

        try {
            $router->dispatch(new Request('GET', $requestPath));
            throw new RuntimeException('Expected route handler rejection.');
        } catch (RouteHandlerException $exception) {
            if ($index === 0) {
                $assertSame(true, str_ends_with($exception->getMessage(), '::show\' is not publicly callable.'));
            } else {
                $assertSame($message, $exception->getMessage());
            }
        }
    }
});

$test('nested route groups normalize boundaries and preserve middleware declaration order', static function () use ($assertSame): void {
    $makeMiddleware = static fn (): Middleware => new class implements Middleware {
        public function process(Request $request, RequestHandler $next): ResponseInterface
        {
            return $next->handle($request);
        }
    };
    $outer = $makeMiddleware();
    $inner = $makeMiddleware();
    $routeMiddleware = $makeMiddleware();
    $router = new Router();

    $router->group([
        'prefix' => '/api/',
        'name' => 'api.',
        'middleware' => [$outer],
    ], static function (Router $router) use ($inner, $routeMiddleware): void {
        $router->group([
            'prefix' => '/v1/',
            'name' => 'v1.',
            'middleware' => [$inner],
        ], static function (Router $router) use ($routeMiddleware): void {
            $router->get('/users/', static fn (): string => 'users', 'users.index')
                ->middleware($routeMiddleware);
        });
    });

    $route = $router->routes()[0];
    $assertSame('/api/v1/users', $route->path);
    $assertSame('api.v1.users.index', $route->name);
    $assertSame([$outer, $inner, $routeMiddleware], $route->middlewareStack());
    $assertSame('/api/v1/users', $router->url('api.v1.users.index'));
});

$test('duplicate method and normalized path registrations fail without polluting names', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/accounts/', static fn (): string => 'first', 'accounts.index');
    $router->post('/accounts', static fn (): string => 'post');

    foreach ([
        [['GET'], '/accounts', 'accounts.duplicate', 'GET'],
        [['HEAD'], '/accounts/', 'accounts.head', 'HEAD'],
    ] as [$methods, $path, $name, $method]) {
        try {
            $router->match($methods, $path, static fn (): string => 'duplicate', $name);
            throw new RuntimeException('Expected duplicate route registration rejection.');
        } catch (RouteDefinitionException $exception) {
            $assertSame(true, str_contains($exception->getMessage(), "Route '/accounts' is already registered"));
            $assertSame(true, str_contains($exception->getMessage(), $method));
        }

        try {
            $router->url($name);
            throw new RuntimeException('Expected failed route name not to be registered.');
        } catch (UrlGenerationException) {
        }
    }
});

$test('static routes take precedence over earlier dynamic routes', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/pages/{page}', static fn (string $page): string => 'dynamic:' . $page);
    $router->get('/pages/create', static fn (): string => 'static');

    $assertSame('static', $router->dispatch(new Request('GET', '/pages/create'))->content());
    $assertSame('dynamic:about', $router->dispatch(new Request('GET', '/pages/about'))->content());
});

$test('GET routes provide HEAD fallback and stable Allow methods while OPTIONS stays explicit', static function () use ($assertSame): void {
    $router = new Router();
    $router->match(['DELETE', 'POST', 'GET'], '/documents', static fn (): string => 'document');

    $head = $router->dispatch(new Request('HEAD', '/documents'));
    $assertSame(200, $head->status());
    $assertSame('', $head->content());

    foreach (['PUT', 'OPTIONS'] as $method) {
        try {
            $router->dispatch(new Request($method, '/documents'));
            throw new RuntimeException('Expected method mismatch.');
        } catch (MethodNotAllowed $exception) {
            $assertSame(['GET', 'HEAD', 'POST', 'DELETE'], $exception->allowedMethods);
        }
    }

    $router->options('/documents', static fn (): string => 'options');
    $assertSame('options', $router->dispatch(new Request('OPTIONS', '/documents'))->content());
});

$test('route constraints use whole-segment matching with safe delimiter selection', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/codes/{code}', static fn (string $code): string => $code, 'codes.show')
        ->where('code', '[A-F#]+');

    $assertSame('AB#CD', $router->dispatch(new Request('GET', '/codes/AB%23CD'))->content());
    $assertSame('/codes/AB%23CD', $router->url('codes.show', ['code' => 'AB#CD']));

    foreach (['ABx', 'xAB'] as $invalid) {
        try {
            $router->dispatch(new Request('GET', '/codes/' . $invalid));
            throw new RuntimeException('Expected whole-segment constraint mismatch.');
        } catch (RouteNotFound) {
        }
    }
});

$test('route paths reject malformed parameters and preserve normalized path semantics', static function () use ($assertSame): void {
    foreach (['/users/{}', '/users/{1user}', '/users/{user', '/users/user}'] as $path) {
        try {
            (new Router())->get($path, static fn (): string => 'invalid');
            throw new RuntimeException('Expected invalid route pattern rejection.');
        } catch (RouteDefinitionException $exception) {
            $assertSame(true, str_contains($exception->getMessage(), 'invalid parameter pattern'));
        }
    }

    $router = new Router();
    $router->get('/trailing/', static fn (): string => 'trailing');
    $router->get('/repeated//slash', static fn (): string => 'repeated');
    $router->get('/encoded/slash', static fn (): string => 'encoded');

    $assertSame('trailing', $router->dispatch(new Request('GET', '/trailing/'))->content());
    $assertSame('repeated', $router->dispatch(new Request('GET', '/repeated//slash'))->content());
    $assertSame('encoded', $router->dispatch(new Request('GET', '/encoded%2Fslash'))->content());

    try {
        $router->dispatch(new Request('GET', '/repeated/slash'));
        throw new RuntimeException('Expected repeated slash to remain distinct.');
    } catch (RouteNotFound) {
    }
});


$test('router dispatches static routes', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/health', static fn (): Response => new Response('ok'));

    $response = $router->dispatch(new Request('GET', '/health'));

    $assertSame('ok', $response->content());
});

$test('router exposes ordinary web application HTTP verbs', static function () use ($assertSame): void {
    $router = new Router();
    $router->put('/documents/put', static fn (): string => 'put');
    $router->patch('/documents/patch', static fn (): string => 'patch');
    $router->delete('/documents/delete', static fn (): string => 'delete');
    $router->options('/documents/options', static fn (): string => 'options');

    foreach (['PUT' => 'put', 'PATCH' => 'patch', 'DELETE' => 'delete', 'OPTIONS' => 'options'] as $method => $path) {
        $assertSame(
            $path,
            $router->dispatch(new Request($method, '/documents/' . $path))->content(),
        );
    }
});

$test('route groups compose paths names and middleware in declaration order', static function () use ($assertSame): void {
    $events = new ArrayObject();
    $middleware = static function (string $name) use ($events): Middleware {
        return new class($name, $events) implements Middleware {
            public function __construct(
                private readonly string $name,
                private readonly ArrayObject $events,
            ) {
            }

            public function process(Request $request, RequestHandler $next): ResponseInterface
            {
                $this->events[] = $this->name . ':before';
                $response = $next->handle($request);
                $this->events[] = $this->name . ':after';
                return $response;
            }
        };
    };
    $auth = $middleware('auth');
    $admin = $middleware('admin');
    $router = new Router();

    $router->group([
        'prefix' => '/admin',
        'name' => 'admin.',
        'middleware' => [$auth],
    ], static function (Router $router) use ($admin, $events): void {
        $router->group([
            'middleware' => [$admin],
        ], static function (Router $router) use ($events): void {
            $router->get('/users', static function () use ($events): string {
                $events[] = 'handler';
                return 'users';
            }, 'users.index');
        });
    });

    $route = $router->routes()[0];
    $assertSame('/admin/users', $route->path);
    $assertSame('admin.users.index', $route->name);
    $assertSame([$auth, $admin], $route->middlewareStack());
    $assertSame('/admin/users', $router->url('admin.users.index'));
    $assertSame('users', $router->dispatch(new Request('GET', '/admin/users'))->content());
    $assertSame([
        'auth:before',
        'admin:before',
        'handler',
        'admin:after',
        'auth:after',
    ], $events->getArrayCopy());
});

$test('route group context is restored after a callback throws', static function () use ($assertSame): void {
    $router = new Router();

    try {
        $router->group(['prefix' => '/abandoned'], static function (): void {
            throw new RuntimeException('stop');
        });
    } catch (RuntimeException $exception) {
        $assertSame('stop', $exception->getMessage());
    }

    $router->get('/health', static fn (): string => 'ok', 'health');
    $assertSame('/health', $router->routes()[0]->path);
    $assertSame('/health', $router->url('health'));
});

$test('route parameter constraints select only matching paths', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/users/{user}', static fn (string $user): string => 'id:' . $user)
        ->where('user', '\\d+');
    $router->get('/users/{slug}', static fn (string $slug): string => 'slug:' . $slug)
        ->where('slug', '[a-z]+');

    $assertSame('id:42', $router->dispatch(new Request('GET', '/users/42'))->content());
    $assertSame('slug:ada', $router->dispatch(new Request('GET', '/users/ada'))->content());

    try {
        $router->dispatch(new Request('GET', '/users/Ada-42'));
        throw new RuntimeException('Expected constrained route not to match.');
    } catch (RouteNotFound) {
    }
});

$test('named URLs respect route parameter constraints', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/users/{user}', static fn (): string => 'user', 'users.show')
        ->where('user', '\\d+');

    $assertSame('/users/42', $router->url('users.show', ['user' => 42]));

    try {
        $router->url('users.show', ['user' => 'ada']);
        throw new RuntimeException('Expected constrained URL parameter rejection.');
    } catch (UrlGenerationException $exception) {
        $assertSame(
            "Path parameter 'user' for route 'users.show' does not satisfy its constraint.",
            $exception->getMessage(),
        );
    }
});

$test('route constraints reject unknown parameters and invalid patterns', static function () use ($assertSame): void {
    $route = (new Router())->get('/users/{user}', static fn (): string => 'user');

    try {
        $route->where('account', '\\d+');
        throw new RuntimeException('Expected unknown constraint parameter rejection.');
    } catch (RouteDefinitionException $exception) {
        $assertSame(
            "Route path '/users/{user}' does not contain a parameter named 'account'.",
            $exception->getMessage(),
        );
    }

    try {
        $route->where('user', '[');
        throw new RuntimeException('Expected invalid constraint pattern rejection.');
    } catch (RouteDefinitionException $exception) {
        $assertSame(
            "The constraint for route parameter 'user' is not a valid regular expression.",
            $exception->getMessage(),
        );
    }

    try {
        $route->where('user', '(?P<user>\d+)');
        throw new RuntimeException('Expected named constraint capture rejection.');
    } catch (RouteDefinitionException $exception) {
        $assertSame(
            "The constraint for route parameter 'user' cannot contain a named capture.",
            $exception->getMessage(),
        );
    }

    try {
        (new Router())->match(
            ["GET\r\nX-Injected"],
            '/unsafe',
            static fn (): string => 'unsafe',
        );
        throw new RuntimeException('Expected invalid route method rejection.');
    } catch (RouteDefinitionException) {
    }

    foreach (["/unsafe\r\n", '/unsafe?query=yes', '/unsafe#fragment'] as $path) {
        try {
            (new Router())->get($path, static fn (): string => 'unsafe');
            throw new RuntimeException('Expected invalid route path rejection.');
        } catch (RouteDefinitionException) {
        }
    }
});

$test('spoofed request methods are effective during dispatch', static function () use ($assertSame): void {
    $router = new Router();
    $router->patch('/users/{user}', static fn (string $user): string => 'patched:' . $user);
    $router->delete('/users/{user}', static fn (string $user): string => 'deleted:' . $user);

    $assertSame(
        'patched:42',
        $router->dispatch(new Request('POST', '/users/42', body: ['_method' => 'PATCH']))->content(),
    );
    $assertSame(
        'deleted:42',
        $router->dispatch(new Request(
            'POST',
            '/users/42',
            headers: ['X-HTTP-Method-Override' => 'DELETE'],
        ))->content(),
    );
});

$test('named routes generate encoded paths and RFC 3986 query strings', static function () use ($assertSame): void {
    $router = new Router();
    $router->get(
        '/teams/{team}/users/{user}',
        static fn (string $team, string $user): string => $team . ':' . $user,
        'users.show',
    );

    $url = $router->url(
        'users.show',
        ['team' => 'Core Team', 'user' => 'Ada Lovelace'],
        ['tab' => 'account settings', 'filter' => ['active' => true]],
    );

    $assertSame(
        '/teams/Core%20Team/users/Ada%20Lovelace?tab=account%20settings&filter%5Bactive%5D=1',
        $url,
    );
});

$test('generated path values are decoded exactly once during dispatch', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/references/{reference}', static fn (string $reference): string => $reference, 'references.show');
    $path = $router->url('references.show', ['reference' => '%2F']);

    $assertSame('/references/%252F', $path);
    $assertSame('%2F', $router->dispatch(new Request('GET', $path))->content());
});

$test('named route generation requires the exact path parameter set', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/articles/{article}', static fn (): string => 'article', 'articles.show');

    try {
        $router->url('articles.show');
        throw new RuntimeException('Expected missing route parameter rejection.');
    } catch (UrlGenerationException $exception) {
        $assertSame(
            "Cannot generate route 'articles.show'; missing path parameters: article.",
            $exception->getMessage(),
        );
    }

    try {
        $router->url('articles.show', ['article' => 42, 'page' => 2]);
        throw new RuntimeException('Expected unknown route parameter rejection.');
    } catch (UrlGenerationException $exception) {
        $assertSame(
            "Cannot generate route 'articles.show'; unknown path parameters: page.",
            $exception->getMessage(),
        );
    }
});

$test('named route generation rejects unknown routes and duplicate names', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/first', static fn (): string => 'first', 'shared.name');

    try {
        $router->url('missing.route');
        throw new RuntimeException('Expected unknown named route rejection.');
    } catch (UrlGenerationException $exception) {
        $assertSame("Named route 'missing.route' does not exist.", $exception->getMessage());
    }

    try {
        $router->get('/second', static fn (): string => 'second', 'shared.name');
        throw new RuntimeException('Expected duplicate route name rejection.');
    } catch (InvalidArgumentException $exception) {
        $assertSame("Route name 'shared.name' is already registered.", $exception->getMessage());
    }
});

$test('named route path parameters cannot be empty or non-stringable', static function () use ($assertSame): void {
    $router = new Router();
    $router->get('/files/{file}', static fn (): string => 'file', 'files.show');

    foreach (['' => 'cannot be empty', 'array' => 'must be scalar or stringable'] as $kind => $message) {
        $value = $kind === '' ? '' : ['not', 'valid'];

        try {
            $router->url('files.show', ['file' => $value]);
            throw new RuntimeException('Expected invalid path parameter rejection.');
        } catch (UrlGenerationException $exception) {
            $assertSame(true, str_contains($exception->getMessage(), $message));
        }
    }

    try {
        $router->url('files.show', ['file' => 'reports/annual.pdf']);
        throw new RuntimeException('Expected slash-containing path parameter rejection.');
    } catch (UrlGenerationException $exception) {
        $assertSame(
            "Path parameter 'file' for route 'files.show' cannot contain a slash.",
            $exception->getMessage(),
        );
    }
});

$test('route paths reject duplicate parameter names', static function () use ($assertSame): void {
    try {
        (new Router())->get('/compare/{item}/{item}', static fn (): string => 'compare');
        throw new RuntimeException('Expected duplicate path parameter rejection.');
    } catch (InvalidArgumentException $exception) {
        $assertSame('Route path parameter names must be unique.', $exception->getMessage());
    }
});

$test('routing accepts custom response interface implementations', static function () use ($assertSame): void {
    $custom = new class implements ResponseInterface {
        public function status(): int
        {
            return 202;
        }

        public function content(): string
        {
            return 'custom';
        }

        public function headers(): array
        {
            return [];
        }

        public function withoutBody(): self
        {
            return $this;
        }

        public function withHeader(string $name, string $value): self
        {
            return $this;
        }

        public function send(): void
        {
        }
    };
    $router = new Router();
    $router->get('/custom-response', static fn (): ResponseInterface => $custom);

    $assertSame($custom, $router->dispatch(new Request('GET', '/custom-response')));
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
    $router->post('/messages', static fn (): mixed => null);

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

$test('cookies serialize secure attributes and responses preserve repeated cookies', static function () use ($assertSame): void {
    $cookie = Cookie::make(
        name: 'theme',
        value: 'dark mode/' . "\u{00FC}" . ';=',
        expires: new DateTimeImmutable('2030-01-02 03:04:05+00:00'),
        path: '/preferences',
        secure: true,
        httpOnly: true,
        sameSite: SameSite::Lax,
        domain: 'example.com',
        maxAge: 3600,
    );
    $language = Cookie::make(
        name: 'language',
        value: 'en',
        secure: false,
        httpOnly: false,
        sameSite: SameSite::Strict,
    );
    $original = new Response('Saved', headers: ['Set-Cookie' => 'legacy=value; Path=/']);
    $response = $original->withCookie($cookie)->withCookie($language);
    $header = $cookie->toHeader();

    $assertSame([], $original->cookies());
    $assertSame([$cookie, $language], $response->cookies());
    $assertSame([$cookie, $language], $response->withoutBody()->cookies());
    $assertSame(true, str_starts_with($header, 'theme=dark%20mode%2F%C3%BC%3B%3D; '));
    $assertSame(true, str_contains($header, 'Expires=Wed, 02 Jan 2030 03:04:05 GMT'));
    $assertSame(true, str_contains($header, 'Max-Age=3600'));
    $assertSame(true, str_contains($header, 'Path=/preferences'));
    $assertSame(true, str_contains($header, 'Domain=example.com'));
    $assertSame(true, str_contains($header, 'Secure'));
    $assertSame(true, str_contains($header, 'HttpOnly'));
    $assertSame(true, str_contains($header, 'SameSite=Lax'));
    $assertSame('legacy=value; Path=/', $response->headers()['Set-Cookie']);
    $assertSame(false, str_contains($language->toHeader(), 'Secure'));
    $assertSame(false, str_contains($language->toHeader(), 'HttpOnly'));
});

$test('cookies support SameSite None deletion and duplicate names with distinct paths', static function () use ($assertSame): void {
    $none = Cookie::make(
        name: 'cross_site',
        value: 'allowed',
        secure: true,
        httpOnly: true,
        sameSite: SameSite::None,
    );
    $root = Cookie::make(
        name: 'theme',
        value: 'root',
        path: '/',
    );
    $admin = Cookie::make(
        name: 'theme',
        value: 'admin',
        path: '/admin',
    );
    $deletion = Cookie::forget(
        name: 'session',
        path: '/account',
        secure: true,
        httpOnly: true,
        sameSite: SameSite::Strict,
        domain: 'example.com',
    );
    $response = Response::html('cookies')
        ->withCookie($root)
        ->withCookie($admin)
        ->withCookie($none)
        ->withCookie($deletion);

    $assertSame([$root, $admin, $none, $deletion], $response->cookies());
    $noneHeader = $none->toHeader();
    $assertSame(true, str_contains($noneHeader, 'Secure'));
    $assertSame(true, str_contains($noneHeader, 'SameSite=None'));
    $deletionHeader = $deletion->toHeader();
    $assertSame(true, str_starts_with($deletionHeader, 'session=; '));
    $assertSame(true, str_contains($deletionHeader, 'Expires=Thu, 01 Jan 1970 00:00:00 GMT'));
    $assertSame(true, str_contains($deletionHeader, 'Max-Age=0'));
    $assertSame(true, str_contains($deletionHeader, 'Path=/account'));
    $assertSame(true, str_contains($deletionHeader, 'Domain=example.com'));
    $assertSame(true, str_contains($deletionHeader, 'SameSite=Strict'));
});

$test('cookies reject unsafe names values attributes and expiration', static function (): void {
    $invalidCookies = [
        static fn (): Cookie => Cookie::make(name: 'bad name', value: 'value'),
        static fn (): Cookie => Cookie::make(name: 'theme', value: "safe\r\nSet-Cookie: injected=yes"),
        static fn (): Cookie => Cookie::make(name: 'theme', value: "dark\0mode"),
        static fn (): Cookie => Cookie::make(name: 'theme', value: "dark\tmode"),
        static fn (): Cookie => Cookie::make(name: 'theme', value: 'dark', path: "/\r\n"),
        static fn (): Cookie => Cookie::make(name: 'theme', value: 'dark', path: 'relative'),
        static fn (): Cookie => Cookie::make(name: 'theme', value: 'dark', path: '/bad;path'),
        static fn (): Cookie => Cookie::make(name: 'theme', value: 'dark', path: '/bad path'),
        static fn (): Cookie => Cookie::make(name: 'theme', value: 'dark', path: "/\u{00FC}nicode"),
        static fn (): Cookie => Cookie::make(name: 'theme', value: 'dark', sameSite: 'Unsupported'),
        static fn (): Cookie => Cookie::make(
            name: 'theme',
            value: 'dark',
            secure: false,
            sameSite: SameSite::None,
        ),
        static fn (): Cookie => Cookie::make(
            name: 'theme',
            value: 'dark',
            expires: new DateTimeImmutable('1500-01-01 00:00:00+00:00'),
        ),
        static fn (): Cookie => Cookie::make(name: '__Secure-id', value: 'value', secure: false),
        static fn (): Cookie => Cookie::make(name: '__Host-id', value: 'value', path: '/app', secure: true),
        static fn (): Cookie => Cookie::make(name: 'theme', value: 'dark', domain: ''),
        static fn (): Cookie => Cookie::make(name: 'theme', value: 'dark', domain: "example.com\r\n"),
        static fn (): Cookie => Cookie::make(name: 'theme', value: 'dark', domain: 'example.com;Secure'),
        static fn (): Cookie => Cookie::make(name: 'theme', value: 'dark', domain: "\u{00FC}nicode.example"),
        static fn (): Cookie => Cookie::make(name: 'theme', value: 'dark', domain: '.example.com'),
        static fn (): Cookie => Cookie::make(name: 'theme', value: 'dark', maxAge: -1),
        static fn (): Cookie => Cookie::make(name: '__Host-id', value: 'value', domain: 'example.com'),
    ];

    foreach ($invalidCookies as $createCookie) {
        try {
            $createCookie();
            throw new RuntimeException('Expected invalid cookie rejection.');
        } catch (InvalidArgumentException) {
        }
    }

    try {
        new Response('', cookies: ['not-a-cookie']);
        throw new RuntimeException('Expected invalid response cookie rejection.');
    } catch (InvalidArgumentException) {
    }
});

$test('native sessions reject unsafe configuration', static function (): void {
    $invalidSessions = [
        static fn (): NativeSession => new NativeSession(name: 'bad_session_name'),
        static fn (): NativeSession => new NativeSession(path: "/\r\n"),
        static fn (): NativeSession => new NativeSession(path: 'relative'),
        static fn (): NativeSession => new NativeSession(path: '/bad;path'),
        static fn (): NativeSession => new NativeSession(path: '/bad path'),
        static fn (): NativeSession => new NativeSession(path: "/\u{00FC}nicode"),
        static fn (): NativeSession => new NativeSession(domain: ''),
        static fn (): NativeSession => new NativeSession(domain: "example.com\r\n"),
        static fn (): NativeSession => new NativeSession(domain: 'example.com;Secure'),
        static fn (): NativeSession => new NativeSession(domain: "\u{00FC}nicode.example"),
        static fn (): NativeSession => new NativeSession(lifetime: -1),
        static fn (): NativeSession => new NativeSession(sameSite: 'Unsupported'),
        static fn (): NativeSession => new NativeSession(
            secure: false,
            sameSite: SameSite::None,
        ),
    ];

    foreach ($invalidSessions as $createSession) {
        try {
            $createSession();
            throw new RuntimeException('Expected invalid native session configuration rejection.');
        } catch (InvalidArgumentException) {
        }
    }
});

$test('native session operations fail without leaking warnings after output starts', static function () use ($assertSame): void {
    if (!headers_sent()) {
        return;
    }

    $warnings = [];
    $previous = set_error_handler(
        static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        },
    );

    try {
        try {
            (new NativeSession(name: 'MEULAHLATESESSION', secure: false))->start();
            throw new RuntimeException('Expected late native session rejection.');
        } catch (SessionException $exception) {
            $assertSame(true, str_contains($exception->getMessage(), 'after response output has started'));
            $assertSame(false, str_contains($exception->getMessage(), 'Native PHP reported'));
        }
    } finally {
        if ($previous !== null) {
            set_error_handler($previous);
        } else {
            restore_error_handler();
        }
    }

    $assertSame([], $warnings);
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
$test('response sending fails explicitly after output has started', static function (): void {
    if (!headers_sent()) {
        return;
    }

    try {
        Response::html('late')->send();
        throw new RuntimeException('Expected late response send rejection.');
    } catch (ResponseException) {
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

$test('configuration loads application configuration files', static function () use ($assertSame): void {
    $config = Repository::load(__DIR__ . '/fixtures/config');

    $assertSame(true, $config->has('app.environment'));
    $assertSame('mysql', $config->string('database.driver'));
});

$test('project root discovery walks up from an application subdirectory', static function () use ($assertSame): void {
    $applicationRoot = realpath(__DIR__ . '/fixtures/application');

    if ($applicationRoot === false) {
        throw new RuntimeException('Application fixture is missing.');
    }

    $assertSame($applicationRoot, ProjectRoot::discover($applicationRoot . '/public'));
    $assertSame($applicationRoot, ProjectRoot::discover($applicationRoot . '/database/migrations'));
});

$test('project root discovery honors the explicit environment root first', static function () use ($assertSame): void {
    $key = 'MEULAH_APPLICATION_ROOT';
    $original = $_ENV[$key] ?? null;
    $existed = array_key_exists($key, $_ENV);
    $applicationRoot = realpath(__DIR__ . '/fixtures/application');

    if ($applicationRoot === false) {
        throw new RuntimeException('Application fixture is missing.');
    }

    try {
        $_ENV[$key] = $applicationRoot;
        $assertSame($applicationRoot, ProjectRoot::discover(dirname(__DIR__)));
    } finally {
        if ($existed) {
            $_ENV[$key] = $original;
        } else {
            unset($_ENV[$key]);
        }
    }
});

$test('project root discovery rejects unmarked Composer projects', static function () use ($assertSame): void {
    try {
        ProjectRoot::explicit(dirname(__DIR__));
        throw new RuntimeException('Expected unmarked project rejection.');
    } catch (RuntimeException $exception) {
        $assertSame(
            true,
            str_starts_with($exception->getMessage(), 'Directory is not a marked Meulah application:'),
        );
    }
});

$test('global help aliases run before application-root discovery', static function () use ($assertSame): void {
    foreach (['--help', '-h'] as $option) {
        $discoveries = 0;
        $output = ConsoleOutput::buffered();
        $launcher = new Launcher(
            $output,
            static function () use (&$discoveries): string {
                $discoveries++;
                throw new RuntimeException('Root discovery must not run.');
            },
            static fn (): string => 'test-version',
        );

        $assertSame(0, $launcher->run(['meulah', $option]));
        $assertSame(0, $discoveries);
        $assertSame(true, str_contains($output->output(), 'Meulah CLI'));
        $assertSame(true, str_contains($output->output(), 'Global options:'));
        $assertSame(true, str_contains($output->output(), 'Application commands require a Meulah application.'));
        $assertSame(false, str_contains($output->output(), 'migrate'));
        $assertSame('', $output->errorOutput());
    }
});

$test('global version aliases use the resolver before application-root discovery', static function () use ($assertSame): void {
    foreach (['--version', '-V'] as $option) {
        $discoveries = 0;
        $output = ConsoleOutput::buffered();
        $launcher = new Launcher(
            $output,
            static function () use (&$discoveries): string {
                $discoveries++;
                throw new RuntimeException('Root discovery must not run.');
            },
            static fn (): string => '0.2.0-test',
        );

        $assertSame(0, $launcher->run(['meulah', $option]));
        $assertSame(0, $discoveries);
        $assertSame('Meulah CLI 0.2.0-test' . PHP_EOL, $output->output());
        $assertSame('', $output->errorOutput());
    }
});

$test('unknown commands are classified without application-root discovery', static function () use ($assertSame): void {
    $discoveries = 0;
    $output = ConsoleOutput::buffered();
    $launcher = new Launcher(
        $output,
        static function () use (&$discoveries): string {
            $discoveries++;
            throw new RuntimeException('Root discovery must not run.');
        },
        static fn (): string => 'test-version',
    );

    $assertSame(1, $launcher->run(['meulah', 'migrte']));
    $assertSame(0, $discoveries);
    $assertSame('', $output->output());
    $assertSame(true, str_contains($output->errorOutput(), "Command 'migrte' is not defined."));
    $assertSame(true, str_contains($output->errorOutput(), "Did you mean 'migrate'?"));
});

$test('installed CLI global information works outside an application', static function () use ($assertSame, $runCli): void {
    $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meulah_cli_' . bin2hex(random_bytes(6));

    if (!mkdir($outside, 0775, true) && !is_dir($outside)) {
        throw new RuntimeException('Unable to create the outside-application CLI fixture.');
    }

    try {
        foreach (['--help', '-h'] as $option) {
            $result = $runCli([$option], $outside);
            $assertSame(0, $result['status']);
            $assertSame('', $result['error']);
            $assertSame(true, str_contains($result['output'], 'Global options:'));
            $assertSame(false, str_contains($result['output'], 'migrate'));
        }

        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $expectedVersion = 'Meulah CLI ' . FrameworkVersion::current() . PHP_EOL;

        foreach (['--version', '-V'] as $option) {
            $result = $runCli([$option], $outside);
            $assertSame(0, $result['status']);
            $assertSame($expectedVersion, $result['output']);
            $assertSame('', $result['error']);
        }
    } finally {
        rmdir($outside);
    }
});

$test('installed CLI application commands require a valid application root', static function () use ($assertSame, $runCli): void {
    $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meulah_cli_' . bin2hex(random_bytes(6));

    if (!mkdir($outside, 0775, true) && !is_dir($outside)) {
        throw new RuntimeException('Unable to create the outside-application CLI fixture.');
    }

    try {
        foreach (['migrate', 'migrate:status'] as $command) {
            $result = $runCli([$command], $outside);
            $assertSame(1, $result['status']);
            $assertSame('', $result['output']);
            $assertSame(
                'Error: No Meulah application was found. Run this command inside a Meulah application or set MEULAH_APPLICATION_ROOT.' . PHP_EOL,
                $result['error'],
            );
        }

        $invalidRoot = $outside . DIRECTORY_SEPARATOR . 'missing';
        $result = $runCli(['migrate'], $outside, ['MEULAH_APPLICATION_ROOT' => $invalidRoot]);
        $assertSame(1, $result['status']);
        $assertSame('', $result['output']);
        $assertSame('Error: Application directory does not exist: ' . $invalidRoot . PHP_EOL, $result['error']);
    } finally {
        rmdir($outside);
    }
});

$test('installed CLI application commands work inside and through an explicit root', static function () use ($assertSame, $runCli): void {
    $applicationRoot = realpath(__DIR__ . '/fixtures/application');
    $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meulah_cli_' . bin2hex(random_bytes(6));

    if ($applicationRoot === false) {
        throw new RuntimeException('Application fixture is missing.');
    }

    if (!mkdir($outside, 0775, true) && !is_dir($outside)) {
        throw new RuntimeException('Unable to create the outside-application CLI fixture.');
    }

    try {
        $inside = $runCli(['migrate', '--help'], $applicationRoot);
        $assertSame(0, $inside['status']);
        $assertSame('', $inside['error']);
        $assertSame(true, str_contains($inside['output'], 'Run all pending migrations.'));

        $explicit = $runCli(
            ['migrate:status', '--help'],
            $outside,
            ['MEULAH_APPLICATION_ROOT' => $applicationRoot],
        );
        $assertSame(0, $explicit['status']);
        $assertSame('', $explicit['error']);
        $assertSame(true, str_contains($explicit['output'], 'Show migration status.'));
    } finally {
        rmdir($outside);
    }
});

$test('installed CLI rejects unknown global options and unknown commands outside applications', static function () use ($assertSame, $runCli): void {
    $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meulah_cli_' . bin2hex(random_bytes(6));

    if (!mkdir($outside, 0775, true) && !is_dir($outside)) {
        throw new RuntimeException('Unable to create the outside-application CLI fixture.');
    }

    try {
        $option = $runCli(['--unknown'], $outside);
        $assertSame(1, $option['status']);
        $assertSame('', $option['output']);
        $assertSame(
            "Error: Unknown global option '--unknown'. Run 'meulah --help' for usage." . PHP_EOL,
            $option['error'],
        );

        $command = $runCli(['unknown-command'], $outside);
        $assertSame(1, $command['status']);
        $assertSame('', $command['output']);
        $assertSame(
            "Command 'unknown-command' is not defined." . PHP_EOL
                . "Run 'meulah --help' for global usage." . PHP_EOL,
            $command['error'],
        );
    } finally {
        rmdir($outside);
    }
});

$test('console input preserves arguments and explicit option values', static function () use ($assertSame): void {
    $input = ConsoleInput::fromTokens('demo', [
        'first',
        '--path=C:\\apps\\site\\database\\migrations',
        '--empty=',
        '--force',
        '0',
        '--',
        '--literal',
        'tail',
    ]);

    $assertSame('demo', $input->command());
    $assertSame(['first', '0', '--literal', 'tail'], $input->arguments());
    $assertSame('C:\\apps\\site\\database\\migrations', $input->option('path'));
    $assertSame('', $input->option('empty'));
    $assertSame(true, $input->option('force'));
    $assertSame(false, $input->hasOption('missing'));
    $assertSame('fallback', $input->option('missing', 'fallback'));

    $unix = ConsoleInput::fromTokens('demo', [
        '--path=/srv/app/database/migrations',
        '--enabled=false',
        '--count=0',
    ]);
    $assertSame('/srv/app/database/migrations', $unix->option('path'));
    $assertSame('false', $unix->option('enabled'));
    $assertSame('0', $unix->option('count'));

    try {
        ConsoleInput::fromTokens('demo', ['--=invalid']);
        throw new RuntimeException('Expected empty option name rejection.');
    } catch (ConsoleInputException $exception) {
        $assertSame('An option name cannot be empty.', $exception->getMessage());
    }

    try {
        ConsoleInput::fromTokens('demo', ['--force', '--force=false']);
        throw new RuntimeException('Expected duplicate option rejection.');
    } catch (ConsoleInputException $exception) {
        $assertSame("Option '--force' cannot be provided more than once.", $exception->getMessage());
    }
});

$test('console input exposes explicit command validation without coercion', static function () use ($assertSame): void {
    $input = ConsoleInput::fromTokens('demo', ['value', '--path=', '--force=false']);

    try {
        $input->assertOnlyOptions(['path']);
        throw new RuntimeException('Expected unknown option rejection.');
    } catch (ConsoleInputException $exception) {
        $assertSame("Unknown option '--force' for command 'demo'.", $exception->getMessage());
    }

    try {
        $input->assertArgumentCount(0, 0);
        throw new RuntimeException('Expected positional argument rejection.');
    } catch (ConsoleInputException $exception) {
        $assertSame("Command 'demo' expects no arguments; 1 given.", $exception->getMessage());
    }

    try {
        $input->assertFlag('force');
        throw new RuntimeException('Expected valued flag rejection.');
    } catch (ConsoleInputException $exception) {
        $assertSame("Option '--force' is a flag and does not accept a value.", $exception->getMessage());
    }
});

$test('built-in console commands reject unknown options surplus arguments and malformed flags', static function () use ($assertSame): void {
    $root = __DIR__ . '/fixtures/application';
    $cases = [
        [['meulah', 'migrate', '--unknown'], "Unknown option '--unknown' for command 'migrate'."],
        [['meulah', 'migrate', 'unexpected'], "Command 'migrate' expects no arguments; 1 given."],
        [
            ['meulah', 'migrate', '--path', 'database/migrations'],
            "Command 'migrate' expects no arguments; 1 given.",
        ],
        [
            ['meulah', 'migrate:rollback', '--force=false'],
            'Destructive migration commands require --force as a bare flag.',
        ],
        [
            ['meulah', 'migrate', '--help', '--unknown'],
            "Unknown option '--unknown' for command 'migrate'.",
        ],
        [
            ['meulah', 'migrate', '--help=value'],
            "Option '--help' is a flag and does not accept a value.",
        ],
        [
            ['meulah', 'list', 'unexpected'],
            "Command 'list' does not accept 1 additional argument.",
        ],
        [
            ['meulah', 'help', 'migrate', 'unexpected'],
            "Command 'help' does not accept 1 additional argument.",
        ],
    ];

    foreach ($cases as [$arguments, $message]) {
        $output = ConsoleOutput::buffered();
        $status = (new ConsoleEntrypoint($root, $output))->run($arguments);

        $assertSame(1, $status);
        $assertSame('', $output->output());
        $assertSame('Error: ' . $message . PHP_EOL, $output->errorOutput());
    }
});

$test('migration path overrides preserve Unix and Windows filesystem roots', static function () use ($assertSame): void {
    $context = new \Meulah\Console\MigrationContext(__DIR__ . '/fixtures/application');
    $windowsRoot = 'C:\\';

    $assertSame('/', $context->migrationPath(ConsoleInput::fromTokens('migrate', ['--path=/'])));
    $assertSame($windowsRoot, $context->migrationPath(ConsoleInput::fromTokens('migrate', ['--path=' . $windowsRoot])));
});

$test('command registry resolves aliases rejects collisions and sorts commands', static function () use ($assertSame): void {
    $makeCommand = static function (string $name, array $aliases = []): Command {
        return new class($name, $aliases) implements Command {
            /** @param list<string> $aliases */
            public function __construct(
                private readonly string $commandName,
                private readonly array $commandAliases,
            ) {
            }

            public function name(): string
            {
                return $this->commandName;
            }

            public function description(): string
            {
                return 'Test command.';
            }

            public function aliases(): array
            {
                return $this->commandAliases;
            }

            public function execute(ConsoleInput $input, ConsoleOutput $output): int
            {
                return 0;
            }
        };
    };

    $registry = new CommandRegistry();
    $beta = $makeCommand('beta', ['b']);
    $alpha = $makeCommand('alpha', ['a']);
    $registry->add($beta);
    $registry->add($alpha);

    $assertSame($alpha, $registry->get('alpha'));
    $assertSame($alpha, $registry->get('a'));
    $assertSame(['alpha', 'beta'], array_map(
        static fn (Command $command): string => $command->name(),
        $registry->commands(),
    ));

    foreach ([
        $makeCommand('alpha'),
        $makeCommand('gamma', ['a']),
        $makeCommand('b'),
    ] as $duplicate) {
        try {
            $registry->add($duplicate);
            throw new RuntimeException('Expected command registration collision.');
        } catch (InvalidArgumentException $exception) {
            $assertSame(true, str_contains($exception->getMessage(), 'already registered'));
        }
    }

    try {
        (new CommandRegistry())->add($makeCommand('duplicate-alias', ['same', 'same']));
        throw new RuntimeException('Expected duplicate alias rejection.');
    } catch (InvalidArgumentException $exception) {
        $assertSame("Command 'duplicate-alias' has duplicate aliases.", $exception->getMessage());
    }

    $suggestions = new CommandRegistry();
    $suggestions->add($makeCommand('cat'));
    $suggestions->add($makeCommand('bat'));
    $assertSame(['bat', 'cat'], $suggestions->suggestions('hat'));
});

$test('console output separates and captures stdout and stderr without ANSI', static function () use ($assertSame): void {
    $buffered = ConsoleOutput::buffered();
    $buffered->writeln('ordinary');
    $buffered->errorln('failure');

    $assertSame('ordinary' . PHP_EOL, $buffered->output());
    $assertSame('failure' . PHP_EOL, $buffered->errorOutput());
    $assertSame(false, str_contains($buffered->output() . $buffered->errorOutput(), "\033"));

    $stdout = fopen('php://memory', 'w+');
    $stderr = fopen('php://memory', 'w+');

    if ($stdout === false || $stderr === false) {
        throw new RuntimeException('Unable to create test output streams.');
    }

    $output = new ConsoleOutput(false, $stdout, $stderr);
    $output->write('out');
    $output->error('err');
    rewind($stdout);
    rewind($stderr);

    $assertSame('out', stream_get_contents($stdout));
    $assertSame('err', stream_get_contents($stderr));

    fclose($stdout);
    fclose($stderr);

    $readOnlyPath = tempnam(sys_get_temp_dir(), 'meulah-output-');
    if ($readOnlyPath === false) {
        throw new RuntimeException('Unable to create read-only output fixture.');
    }
    $readOnly = fopen($readOnlyPath, 'r');
    if ($readOnly === false) {
        unlink($readOnlyPath);
        throw new RuntimeException('Unable to open read-only output fixture.');
    }
    try {
        (new ConsoleOutput(false, $readOnly, $readOnly))->write('cannot-write');
        throw new RuntimeException('Expected console output failure.');
    } catch (OutputException) {
    } finally {
        fclose($readOnly);
        unlink($readOnlyPath);
    }

});

$test('console list order and command failures have stable output and exits', static function () use ($assertSame): void {
    $registry = new CommandRegistry();
    $registry->add(new class implements Command {
        public function name(): string
        {
            return 'zeta';
        }

        public function description(): string
        {
            return 'Last command.';
        }

        public function aliases(): array
        {
            return [];
        }

        public function execute(ConsoleInput $input, ConsoleOutput $output): int
        {
            return 0;
        }
    });
    $registry->add(new class implements Command {
        public function name(): string
        {
            return 'alpha';
        }

        public function description(): string
        {
            return 'First command.';
        }

        public function aliases(): array
        {
            return [];
        }

        public function execute(ConsoleInput $input, ConsoleOutput $output): int
        {
            throw new RuntimeException('Command failed.');
        }
    });
    $listOutput = ConsoleOutput::buffered();
    $console = new ConsoleApplication($registry, $listOutput);

    $assertSame(0, $console->run(['meulah', 'list']));
    $assertSame(
        true,
        strpos($listOutput->output(), 'alpha') < strpos($listOutput->output(), 'zeta'),
    );

    $errorOutput = ConsoleOutput::buffered();
    $console = new ConsoleApplication($registry, $errorOutput);

    $assertSame(1, $console->run(['meulah', 'alpha']));
    $assertSame('', $errorOutput->output());
    $assertSame('Error: Command failed.' . PHP_EOL, $errorOutput->errorOutput());
});

$test('console launches after discovery from nested application directories', static function () use ($assertSame): void {
    $original = getcwd();
    $nested = __DIR__ . '/fixtures/application/database/migrations';

    if ($original === false || !chdir($nested)) {
        throw new RuntimeException('Unable to enter the nested application fixture.');
    }

    try {
        $root = ProjectRoot::discover();
        $output = ConsoleOutput::buffered();
        $console = new ConsoleEntrypoint($root, $output);

        $assertSame(0, $console->run(['meulah', 'list']));
        $assertSame(true, str_contains($output->output(), 'migrate:fresh'));
    } finally {
        chdir($original);
    }
});

$test('console lists commands for empty and list invocations', static function () use ($assertSame): void {
    $root = __DIR__ . '/fixtures/application';

    foreach ([['meulah'], ['meulah', 'list']] as $arguments) {
        $output = ConsoleOutput::buffered();
        $console = new ConsoleEntrypoint($root, $output);

        $assertSame(0, $console->run($arguments));
        $assertSame(true, str_contains($output->output(), 'Meulah CLI'));
        $assertSame(true, str_contains($output->output(), 'make:migration'));
        $assertSame(true, str_contains($output->output(), 'migrate:status'));
    }
});

$test('console renders command help without executing the command', static function () use ($assertSame): void {
    $root = __DIR__ . '/fixtures/application';

    foreach ([
        ['meulah', 'help', 'migrate'],
        ['meulah', 'migrate', '--help'],
    ] as $arguments) {
        $output = ConsoleOutput::buffered();
        $console = new ConsoleEntrypoint($root, $output);

        $assertSame(0, $console->run($arguments));
        $assertSame(true, str_contains($output->output(), 'Run all pending migrations.'));
        $assertSame(true, str_contains($output->output(), 'php meulah migrate'));
    }
});

$test('console unknown commands fail and suggest close matches', static function () use ($assertSame): void {
    $output = ConsoleOutput::buffered();
    $console = new ConsoleEntrypoint(__DIR__ . '/fixtures/application', $output);

    $assertSame(1, $console->run(['meulah', 'migrte']));
    $assertSame(true, str_contains($output->errorOutput(), "Command 'migrte' is not defined."));
    $assertSame(true, str_contains($output->errorOutput(), "Did you mean 'migrate'?"));
});

$test('console executes registered command aliases with parsed input', static function () use ($assertSame): void {
    $output = ConsoleOutput::buffered();
    $console = new ConsoleApplication(output: $output);
    $console->add(new class implements Command {
        public function name(): string
        {
            return 'greet';
        }

        public function description(): string
        {
            return 'Greet somebody.';
        }

        public function aliases(): array
        {
            return ['hello'];
        }

        public function execute(ConsoleInput $input, ConsoleOutput $output): int
        {
            $style = $input->option('style', 'plain');
            $output->writeln($input->command() . ':' . $input->argument(0) . ':' . $style);
            return 7;
        }
    });

    $assertSame(7, $console->run(['meulah', 'hello', 'Ada', '--style=warm']));
    $assertSame('hello:Ada:warm' . PHP_EOL, $output->output());
});

$test('make migration never overwrites an existing timestamped file', static function () use ($assertSame): void {
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meulah_collision_' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create migration collision fixture.');
    }

    $files = [];
    try {
        for ($offset = 0; $offset <= 5; $offset++) {
            $file = $directory . DIRECTORY_SEPARATOR
                . gmdate('Y_m_d_His', time() + $offset)
                . '_collision.php';
            file_put_contents($file, 'existing');
            $files[] = $file;
        }

        $command = new \Meulah\Console\Commands\MakeMigrationCommand(
            new \Meulah\Console\MigrationContext(__DIR__ . '/fixtures/application'),
        );

        try {
            $command->execute(
                ConsoleInput::fromTokens('make:migration', ['collision', '--path=' . $directory]),
                ConsoleOutput::buffered(),
            );
            throw new RuntimeException('Expected migration collision rejection.');
        } catch (RuntimeException $exception) {
            $assertSame(true, str_starts_with($exception->getMessage(), 'Migration already exists:'));
        }

        foreach ($files as $file) {
            $assertSame('existing', file_get_contents($file));
        }
    } finally {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});
$test('migration commands reject missing and empty path option values', static function () use ($assertSame): void {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        return;
    }

    foreach (['--path', '--path='] as $pathOption) {
        $output = ConsoleOutput::buffered();
        $console = new ConsoleEntrypoint(__DIR__ . '/fixtures/application', $output);
        $status = $console->run(['meulah', 'migrate:status', $pathOption]);
        $assertSame(1, $status);
        $assertSame('', $output->output());
        $assertSame("Error: Option '--path' requires a non-empty directory value." . PHP_EOL, $output->errorOutput());
    }
});

$test('migration status runs through its command object', static function () use ($assertSame): void {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        return;
    }

    $output = ConsoleOutput::buffered();
    $console = new ConsoleEntrypoint(__DIR__ . '/fixtures/application', $output);

    $assertSame(0, $console->run(['meulah', 'migrate:status']));
    $assertSame('No migrations found.' . PHP_EOL, $output->output());
});

$test('every migration console command preserves the migration lifecycle', static function () use ($assertSame): void {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        return;
    }

    $root = realpath(__DIR__ . '/fixtures/application');
    $migrationPath = realpath(__DIR__ . '/fixtures/migrations');
    $databasePath = tempnam(sys_get_temp_dir(), 'meulah_console_');
    $makePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meulah_make_' . bin2hex(random_bytes(6));

    if (
        $root === false
        || $migrationPath === false
        || $databasePath === false
        || !mkdir($makePath, 0775, true)
    ) {
        throw new RuntimeException('Unable to prepare console migration fixtures.');
    }

    $databaseKey = 'MEULAH_TEST_DATABASE_PATH';
    $hadDatabase = array_key_exists($databaseKey, $_ENV);
    $originalDatabase = $_ENV[$databaseKey] ?? null;
    $_ENV[$databaseKey] = $databasePath;

    $run = static function (array $arguments) use ($root): array {
        $output = ConsoleOutput::buffered();
        $status = (new ConsoleEntrypoint($root, $output))->run($arguments);

        return [$status, $output->output(), $output->errorOutput()];
    };

    try {
        [$status, $stdout, $stderr] = $run([
            'meulah',
            'make:migration',
            'Create Users Table',
            '--path=' . $makePath,
        ]);
        $created = glob($makePath . DIRECTORY_SEPARATOR . '*_create_users_table.php') ?: [];

        $assertSame(0, $status);
        $assertSame(1, count($created));
        $assertSame(true, str_starts_with($stdout, 'Created: '));
        $assertSame('', $stderr);

        $pathOption = '--path=' . $migrationPath;
        [$status, $stdout, $stderr] = $run(['meulah', 'migrate', $pathOption]);
        $assertSame(0, $status);
        $assertSame(true, str_contains($stdout, 'Migrated: 2026_01_01_000001_create_alpha'));
        $assertSame(true, str_contains($stdout, 'Migrated: 2026_01_01_000002_create_beta'));
        $assertSame('', $stderr);

        [$status, $stdout] = $run(['meulah', 'migrate:status', $pathOption]);
        $assertSame(0, $status);
        $assertSame(true, str_contains($stdout, 'Status'));
        $assertSame(true, str_contains($stdout, 'Ran'));

        [$status, $stdout] = $run(['meulah', 'migrate:rollback', $pathOption]);
        $assertSame(0, $status);
        $assertSame(
            true,
            strpos($stdout, '2026_01_01_000002_create_beta')
                < strpos($stdout, '2026_01_01_000001_create_alpha'),
        );

        $assertSame(0, $run(['meulah', 'migrate', $pathOption])[0]);
        [$status, $stdout] = $run(['meulah', 'migrate:reset', $pathOption]);
        $assertSame(0, $status);
        $assertSame(true, str_contains($stdout, 'Rolled back: 2026_01_01_000001_create_alpha'));

        $assertSame(0, $run(['meulah', 'migrate', $pathOption])[0]);
        $pdo = new \PDO('sqlite:' . $databasePath);
        $pdo->exec('CREATE TABLE unrelated_console_data (id INTEGER PRIMARY KEY)');
        $pdo = null;

        [$status, $stdout, $stderr] = $run(['meulah', 'migrate:fresh', $pathOption]);
        $assertSame(0, $status);
        $assertSame(true, str_starts_with($stdout, 'Dropped all tables.' . PHP_EOL));
        $assertSame(true, str_contains($stdout, 'Migrated: 2026_01_01_000002_create_beta'));
        $assertSame('', $stderr);

        $pdo = new \PDO('sqlite:' . $databasePath);

        try {
            $pdo->query('SELECT COUNT(*) FROM unrelated_console_data');
            throw new RuntimeException('Expected fresh command to drop unrelated tables.');
        } catch (\PDOException) {
        }

        $pdo = null;
    } finally {
        if ($hadDatabase) {
            $_ENV[$databaseKey] = $originalDatabase;
        } else {
            unset($_ENV[$databaseKey]);
        }

        foreach (glob($makePath . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($makePath)) {
            rmdir($makePath);
        }

        if (is_file($databasePath)) {
            unlink($databasePath);
        }
    }
});

$test('production destructive migration commands require a bare force flag', static function () use ($assertSame): void {
    if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
        return;
    }

    $root = realpath(__DIR__ . '/fixtures/application');
    $migrationPath = realpath(__DIR__ . '/fixtures/migrations');
    $databasePath = tempnam(sys_get_temp_dir(), 'meulah_production_');

    if ($root === false || $migrationPath === false || $databasePath === false) {
        throw new RuntimeException('Unable to prepare production console fixtures.');
    }

    $environmentKey = 'MEULAH_TEST_APP_ENV';
    $databaseKey = 'MEULAH_TEST_DATABASE_PATH';
    $hadEnvironment = array_key_exists($environmentKey, $_ENV);
    $hadDatabase = array_key_exists($databaseKey, $_ENV);
    $originalEnvironment = $_ENV[$environmentKey] ?? null;
    $originalDatabase = $_ENV[$databaseKey] ?? null;
    $_ENV[$environmentKey] = 'production';
    $_ENV[$databaseKey] = $databasePath;

    $run = static function (array $arguments) use ($root): array {
        $output = ConsoleOutput::buffered();
        $status = (new ConsoleEntrypoint($root, $output))->run($arguments);

        return [$status, $output->output(), $output->errorOutput()];
    };

    try {
        $pathOption = '--path=' . $migrationPath;
        $assertSame(0, $run(['meulah', 'migrate', $pathOption])[0]);

        foreach (['migrate:rollback', 'migrate:reset', 'migrate:fresh'] as $command) {
            [$status, $stdout, $stderr] = $run(['meulah', $command, $pathOption]);
            $assertSame(1, $status);
            $assertSame('', $stdout);
            $assertSame(
                'Error: Destructive migration commands require --force in production.' . PHP_EOL,
                $stderr,
            );
        }

        foreach (['--force=', '--force=false'] as $invalidForce) {
            [$status, $stdout, $stderr] = $run([
                'meulah',
                'migrate:rollback',
                $pathOption,
                $invalidForce,
            ]);
            $assertSame(1, $status);
            $assertSame('', $stdout);
            $assertSame(true, str_contains($stderr, 'require --force'));
        }

        [$status, $stdout, $stderr] = $run([
            'meulah',
            'migrate:rollback',
            $pathOption,
            '--force',
        ]);
        $assertSame(0, $status);
        $assertSame(true, str_contains($stdout, 'Rolled back:'));
        $assertSame('', $stderr);
    } finally {
        if ($hadEnvironment) {
            $_ENV[$environmentKey] = $originalEnvironment;
        } else {
            unset($_ENV[$environmentKey]);
        }

        if ($hadDatabase) {
            $_ENV[$databaseKey] = $originalDatabase;
        } else {
            unset($_ENV[$databaseKey]);
        }

        if (is_file($databasePath)) {
            unlink($databasePath);
        }
    }
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
