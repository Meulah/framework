<?php

declare(strict_types=1);

use Meulah\Application;
use Meulah\Container\BindingResolutionException;
use Meulah\Container\Container;
use Meulah\Config\Repository;
use Meulah\Console\Application as ConsoleEntrypoint;
use Meulah\Console\Command;
use Meulah\Console\CommandRegistry;
use Meulah\Console\ConsoleInputException;
use Meulah\Console\ConsoleApplication;
use Meulah\Console\Input as ConsoleInput;
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
use Meulah\Routing\RouteNotFound;
use Meulah\Routing\RouteDefinitionException;
use Meulah\Routing\RouteHandlerException;
use Meulah\Routing\Router;
use Meulah\Routing\UrlGenerationException;
use Meulah\Security\Csrf\Csrf;
use Meulah\Security\Csrf\CsrfConfigurationException;
use Meulah\Security\Csrf\VerifyCsrfToken;
use Meulah\Session\NativeSession;
use Meulah\Session\Session;
use Meulah\Session\SessionException;
use Meulah\Support\Environment;
use Meulah\Validation\ValidationException;
use Meulah\Validation\ValidationRuleException;
use Meulah\Validation\Validator;
use Meulah\View\View;
use Tests\Fixtures\CircularOne;
use Tests\Fixtures\EventLog;
use Tests\Fixtures\FriendlyGreeting;
use Tests\Fixtures\Greeting;
use Tests\Fixtures\GreetingController;
use Tests\Fixtures\InvokableGreetingController;
use Tests\Fixtures\ScalarDependencyController;
use Tests\Fixtures\SendWelcomeEmail;
use Tests\Fixtures\UserRegistered;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/EventFixtures.php';
require __DIR__ . '/fixtures/ContainerFixtures.php';

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

$sessionFactory = static function (): Session {
    return new class implements Session {
        /** @var array<string, mixed> */
        public array $data = [];
        private string $identifier = 'test-session-one';

        public function id(): string
        {
            return $this->identifier;
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
        }

        public function put(string $key, mixed $value): void
        {
            $this->data[$key] = $value;
        }

        public function remove(string $key): void
        {
            unset($this->data[$key]);
        }

        public function regenerate(): void
        {
            $this->identifier = 'test-session-' . bin2hex(random_bytes(8));
        }

        public function invalidate(): void
        {
            $this->data = [];
            $this->regenerate();
        }

        public function flash(string $key, mixed $value): void
        {
            $this->put($key, $value);
        }
    };
};

$test('native sessions store regenerate invalidate and age flash data', static function () use ($assertSame): void {
    $originalSavePath = session_save_path();
    $originalName = session_name();
    $originalCookieParameters = session_get_cookie_params();
    $iniKeys = [
        'session.use_strict_mode',
        'session.use_only_cookies',
        'session.use_trans_sid',
        'session.cookie_lifetime',
        'session.cookie_path',
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

        session_id('');
        session_save_path($directory);
        $session = new NativeSession(name: 'MEULAHTESTSESSION', secure: false);

        $assertSame(true, $session instanceof Session);
        $assertSame(false, $session->isStarted());
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
        $session->close();

        $nextRequest = new NativeSession(name: 'MEULAHTESTSESSION', secure: false);
        $assertSame('Saved', $nextRequest->get('notice'));
        $assertSame(42, $nextRequest->get('user_id'));
        $nextRequest->close();

        $followingRequest = new NativeSession(name: 'MEULAHTESTSESSION', secure: false);
        $assertSame('missing', $followingRequest->get('notice', 'missing'));
        $idBeforeInvalidation = $followingRequest->id();
        $followingRequest->invalidate();
        $assertSame(false, hash_equals($idBeforeInvalidation, $followingRequest->id()));
        $assertSame('missing', $followingRequest->get('user_id', 'missing'));
        $followingRequest->close();
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }

        session_id('');
        session_name($originalName);
        session_save_path($originalSavePath);
        session_set_cookie_params($originalCookieParameters);

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

$test('CSRF validation does not create session state for missing tokens', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $csrf = new Csrf($session);

    $assertSame(false, $csrf->isValid(str_repeat('a', 64)));
    $assertSame([], $session->data);
});

$test('CSRF tokens are random stable and bound to the session identifier', static function () use ($assertSame, $sessionFactory): void {
    $session = $sessionFactory();
    $csrf = new Csrf($session);
    $token = $csrf->token();

    $assertSame(64, strlen($token));
    $assertSame(1, preg_match('/^[a-f0-9]{64}$/D', $token));
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
    $assertSame(false, hash_equals($regeneratedToken, $csrf->token()));
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

$test('CSRF exclusions reject route patterns and query strings', static function () use ($sessionFactory): void {
    foreach (['webhooks/provider', '/webhooks/*', '/users/{user}', '/callback?trusted=yes', '/callback%3Ftrusted=yes', '/callback%23fragment', '/webhooks/%2A'] as $exclusion) {
        try {
            new VerifyCsrfToken($sessionFactory(), [$exclusion]);
            throw new RuntimeException('Expected non-explicit CSRF exclusion rejection.');
        } catch (CsrfConfigurationException) {
        }
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

    $assertSame('POST', $form->originalMethod());
    $assertSame('PATCH', $form->method());
    $assertSame('DELETE', $header->method());
    $assertSame('GET', $get->method());
    $assertSame('POST', $query->method());
});

$test('method overrides reject unsupported non-string and conflicting values', static function () use ($assertSame): void {
    foreach (
        [
            ['body' => ['_method' => 'OPTIONS'], 'headers' => []],
            ['body' => ['_method' => ['PATCH']], 'headers' => []],
            ['body' => ['_method' => 'PUT'], 'headers' => ['X-HTTP-Method-Override' => 'DELETE']],
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
        value: 'dark mode',
        expires: new DateTimeImmutable('2030-01-02 03:04:05+00:00'),
        path: '/',
        secure: true,
        httpOnly: true,
        sameSite: SameSite::Lax,
    );
    $language = Cookie::make(
        name: 'language',
        value: 'en',
        secure: false,
        httpOnly: false,
        sameSite: SameSite::Strict,
    );
    $original = Response::html('Saved');
    $response = $original->withCookie($cookie)->withCookie($language);
    $header = $cookie->toHeader();

    $assertSame([], $original->cookies());
    $assertSame([$cookie, $language], $response->cookies());
    $assertSame([$cookie, $language], $response->withoutBody()->cookies());
    $assertSame(true, str_starts_with($header, 'theme=dark%20mode; '));
    $assertSame(true, str_contains($header, 'Expires=Wed, 02 Jan 2030 03:04:05 GMT'));
    $assertSame(true, str_contains($header, 'Path=/'));
    $assertSame(true, str_contains($header, 'Secure'));
    $assertSame(true, str_contains($header, 'HttpOnly'));
    $assertSame(true, str_contains($header, 'SameSite=Lax'));
});

$test('cookies reject unsafe names values attributes and expiration', static function (): void {
    $invalidCookies = [
        static fn (): Cookie => Cookie::make(name: 'bad name', value: 'value'),
        static fn (): Cookie => Cookie::make(name: 'theme', value: "safe\r\nSet-Cookie: injected=yes"),
        static fn (): Cookie => Cookie::make(name: 'theme', value: 'dark', path: "/\r\n"),
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
