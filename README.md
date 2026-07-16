# Meulah

Meulah is a small, explicit PHP framework for conventional server-rendered applications. It keeps the request lifecycle visible and uses modern PHP where it improves safety and clarity.

## Philosophy

- Small enough to understand completely.
- Explicit over magical.
- Secure defaults belong in the framework.
- Plain PHP views and direct PDO access remain first-class choices.
- Application features stay separate from the reusable kernel.
- Existing Meulah applications should have a practical upgrade path.

Meulah currently requires PHP 8.1 or newer.

## Package boundary

This repository contains only the reusable framework package:

```text
src/       reusable framework code (meulah/framework)
bin/       executable installed as vendor/bin/meulah
tests/     framework contract tests
```

The [Meulah application starter](https://github.com/Meulah/meulah) is maintained in its own repository. It owns application concerns: the `App\` namespace, environment file, configuration, bootstrap, routes, controllers, views, migrations, public entry point, and root `meulah` launcher. The framework root is a Composer library and is not itself a web application.

## Request lifecycle

Inside an application created from the starter, the request lifecycle remains visible:

```text
public/index.php
  -> bootstrap.php
  -> Application
  -> routes/web.php
  -> Router
  -> route handler
```

Routes are declared explicitly in `routes/web.php`:

```php
use Meulah\Http\Response;

$router->get('/', static fn (): Response => Response::html('<h1>Hello</h1>'), 'home');
```

The router provides `get()`, `post()`, `put()`, `patch()`, `delete()`, and `options()` for ordinary HTTP routes. Use `match()` when one handler intentionally accepts a specific set of methods.

Related routes can share a path prefix, name prefix, and middleware. Groups may be nested; parent attributes are applied before child attributes:

```php
$router->group([
    'prefix' => '/admin',
    'name' => 'admin.',
    'middleware' => [$auth, $admin],
], function (Router $router): void {
    $router->get('/users', [UserController::class, 'index'], 'users.index');
});

// Path: /admin/users
// Name: admin.users.index
// Middleware: auth, then admin
```

Constrain dynamic path segments with regular-expression fragments. A constraint mismatch behaves like any other non-matching route:

```php
$router
    ->get('/users/{user}', [UserController::class, 'show'], 'users.show')
    ->where('user', '\d+');
```

Constraints only select routes and pass the matched string to the handler. Meulah does not perform implicit route-model binding.

The optional final argument names a route. Generate root-relative URLs through the router rather than hard-coding application paths:

```php
$router->get('/users/{user}', [UserController::class, 'show'], 'users.show');

$url = $router->url(
    'users.show',
    ['user' => 42],
    ['tab' => 'profile'],
);
// /users/42?tab=profile
```

Path parameters are required by name and encoded as URL segments. A single parameter cannot contain `/` or `\`; model multi-segment paths as separate route parameters. The separate query array supports nested values and uses RFC 3986 encoding. Unknown route names, missing or extra path parameters, empty values, and duplicate route names fail immediately with a clear exception. Generated URLs are deliberately root-relative; applications that need an absolute URL should prepend their explicitly configured trusted origin.

Unknown paths return `404 Not Found`. A known path requested with an unsupported HTTP method returns `405 Method Not Allowed` with an `Allow` header.

`Meulah\Http\Response` is the default implementation of `ResponseInterface`. Routing, middleware, request handlers, and the application kernel depend on the interface, so applications may return another compatible response implementation when needed.

## Dependency injection

Controller class handlers are constructed through the application's dependency container. Constructor parameters typed as concrete classes are recursively autowired; interfaces require an explicit binding:

```php
use Meulah\Config\Repository;
use Meulah\Container\Container;

$container = $app->container();
$container->bind(UserRepository::class, PdoUserRepository::class);
$container->singleton(Cache::class, function (Container $container): Cache {
    return new Cache($container->get(Repository::class));
});
$container->instance(Clock::class, $clock);
```

`bind()` creates a new instance for each resolution, `singleton()` reuses the first resolved instance, and `instance()` registers an existing object. Factories receive the container and must return an object.

Controllers can then declare their dependencies normally:

```php
use Meulah\Http\Request;

final class UserController
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function show(Request $request, string $user): string
    {
        return $this->users->find($user)->name;
    }
}

$router->get('/users/{user}', [UserController::class, 'show']);
```

Invokable controller class strings are also supported. Meulah deliberately does not guess scalar values, choose among union types, or invent implementations for interfaces. Register those decisions explicitly; unresolved and circular dependencies produce `BindingResolutionException` with the dependency context.

## Request data

Type the first route-handler parameter as `Request` to receive the current request. Route parameters follow it:

```php
use Meulah\Http\Request;

$router->post('/users/{user}', function (Request $request, string $user): string {
    $trace = $request->header('x-request-id');
    $name = $request->input('name');

    return "Updated {$user}";
});
```

For server-rendered forms, a POST request may explicitly represent `PUT`, `PATCH`, or `DELETE` through a hidden `_method` field:

```html
<form method="POST" action="/users/42">
    <input type="hidden" name="_method" value="PATCH">
</form>
```

The `X-HTTP-Method-Override` header is also supported for clients that cannot send those methods directly. Overrides are considered only when the original method is `POST`, and only `PUT`, `PATCH`, or `DELETE` are accepted. Query-string overrides are ignored; unsupported, non-string, or conflicting form/header values produce a `400` response. The effective method is resolved while constructing the request, before routing. Use `originalMethod()` when application code needs to distinguish the transport method from `method()`.

Request data remains explicit:

```php
$request->header('authorization'); // case-insensitive
$request->headers();
$request->hasHeader('authorization');
$request->query('page');
$request->form('name');
$request->json('email');
$request->json('profile.name');
$request->jsonValue();
$request->jsonObject();
$request->jsonArray();
$request->cookie('session');
$request->file('avatar');
$request->files();
$request->rawBody();
$request->input('name');
$request->allInput();
$request->hasInput('name');
$request->filled('name');
$request->hasFile('avatar');
```

`input()` contains ordinary values only—uploaded files are never merged into it. For form requests, form values override query values. For JSON requests, object fields override query values and form data is ignored. The selected body representation is therefore used first, with query parameters as fallback.

JSON is recognized for `application/json` and `+json` content types. `jsonValue()` and `json()` without a key return the exact decoded shape: objects remain `stdClass`, arrays remain arrays, and scalars remain scalars. `jsonObject()` and `jsonArray()` enforce the expected top-level shape with a `400` error. Keyed and dotted `json()` lookup applies only to top-level JSON objects and otherwise returns the supplied default. An empty JSON body is deliberately treated as an empty object.

Typed input access is strict:

```php
$request->string('name');
$request->integer('page', default: 1);
$request->boolean('published', default: false);
$request->array('roles');
```

Defaults apply only when input is missing. A supplied value of the wrong type produces an `invalid_input` 400 error instead of silently coercing to `0`, `false`, or an empty value. Request data has no merge or replace methods: it continues to represent the original client request, while validation and normalization should produce separate application data.

The raw request body is read once, cached on the request, and shared by `rawBody()` and `json()`. `HTTP_MAX_BODY_SIZE` defaults to 10 MiB and limits how much data Meulah reads into memory; web-server body limits should also remain enabled.

Nested PHP uploads are normalized into `UploadedFile` objects while preserving their original keys. An uploaded file exposes `clientFilename()`, `clientMediaType()`, server-inspected `detectedMediaType()`, `temporaryPath()`, `error()`, and `size()`. Client filenames and client media types are untrusted input. Applications should generate storage names and validate detected type and file contents independently.

Call `isValid()` before `moveTo($destination)`. The destination must be a writable file path inside an existing directory, existing files are never overwritten, and a file cannot be moved twice. `hasMoved()` and `movedPath()` expose its lifecycle. Production uploads require `is_uploaded_file()` and `move_uploaded_file()`; `UploadedFile::forTesting()` provides an explicit filesystem-backed test double without weakening production checks.

Headers and cookies are raw, untrusted client input. In particular, Meulah does not trust `Forwarded` or `X-Forwarded-*` headers automatically, and retrieving a cookie does not validate, decrypt, or turn it into session state.

For requests that expect JSON, malformed bodies and other request errors use a stable machine-readable shape:

```json
{
  "error": {
    "code": "invalid_json",
    "message": "The request body contains malformed JSON."
  }
}
```

Parser detail is omitted in production and included only when development debugging is enabled.

## Response cookies and sessions

Create response cookies through the immutable `Cookie` value object:

```php
use Meulah\Http\Cookie;
use Meulah\Http\SameSite;

$cookie = Cookie::make(
    name: 'theme',
    value: 'dark',
    expires: new DateTimeImmutable('+30 days'),
    path: '/',
    secure: true,
    httpOnly: true,
    sameSite: SameSite::Lax,
);

return Response::html('Saved')->withCookie($cookie);
```

`withCookie()` returns a new response. Multiple calls retain multiple cookies and `send()` emits each one as a separate `Set-Cookie` header. Cookie values are percent-encoded during serialization. Invalid names, CR/LF injection, unsafe paths, unsupported SameSite values, `SameSite=None` without `Secure`, and expiration years outside 1601 through 9999 are rejected before a response can be sent.

Sessions remain an explicit application choice. Bind the contract to the native PHP driver in application bootstrap when session state is needed:

```php
use Meulah\Session\NativeSession;
use Meulah\Session\Session;

$container->singleton(
    Session::class,
    static fn (): Session => new NativeSession(
        name: 'MEULAHSESSID',
        secure: true,
        httpOnly: true,
        sameSite: SameSite::Lax,
    ),
);
```

`NativeSession` starts lazily on the first session operation; reading a request cookie never starts it. It enables PHP strict session IDs, cookie-only transport, disabled URL ID propagation, HttpOnly cookies, and SameSite protection. Its default cookie is Secure; local plain-HTTP development must opt out explicitly with `secure: false`.

```php
$user = $session->get('user');
$session->put('user', $user);
$session->remove('temporary');
$session->regenerate();
$session->invalidate();
$session->flash('notice', 'Profile saved.');
```

`regenerate()` rotates the identifier while preserving data. `invalidate()` clears all session data and rotates the identifier. Flash data is available during the request that creates it and the following request, then is removed when the next request starts. Session operations must happen before response output is sent.

Only the native PHP driver exists in this milestone. Its persistence is controlled by PHP's configured session save handler. File, database, and Redis drivers remain future adapters behind the same `Session` contract.

## CSRF protection

Session-backed forms must register CSRF middleware globally. Use the same session instance for the form helper and middleware:

~~~php
use Meulah\Security\Csrf\Csrf;
use Meulah\Security\Csrf\VerifyCsrfToken;

$csrf = new Csrf($session);

$app->middleware(new VerifyCsrfToken(
    $session,
    except: ['/webhooks/payment-provider'],
));
~~~

Render the hidden field inside every state-changing server-rendered form:

~~~php
<form method="POST" action="/profile">
    <?= $csrf->field() ?>
</form>
~~~

The generated token contains 256 bits of cryptographic randomness, is stored in the session, and is compared with hash_equals(). It is bound to the current session identifier. After regenerate() or invalidate() rotates that identifier, the next CSRF access creates a new token and the previous token no longer validates.

VerifyCsrfToken requires a valid token for every method except GET, HEAD, and OPTIONS. Method spoofing happens first, so forms representing PUT, PATCH, or DELETE are protected as unsafe requests.

JavaScript clients may send the token through the documented header:

~~~text
X-CSRF-Token: <token>
~~~

The middleware reads ordinary forms only from the _token field. If both the field and header are supplied, they must agree. Missing, malformed, stale, or conflicting tokens produce a 419 Page Expired response; JSON clients receive the stable csrf_token_mismatch error code.

Exclusions are exact normalized paths:

~~~php
new VerifyCsrfToken($session, except: [
    '/webhooks/payment-provider',
]);
~~~

Wildcards, route parameters, prefixes, and query-string conditions are rejected. Each excluded endpoint must therefore be an explicit security decision.

## Validation

Validation produces separate application data and never mutates the request. `Validator` is a concrete, stateless class, so it can be constructed directly or injected through Meulah's container:

```php
use Meulah\Validation\Validator;

$result = $validator->validate(
    $request->allInput(),
    [
        'name' => ['required', 'string', 'min:2', 'max:100'],
        'email' => ['required', 'email'],
        'age' => ['nullable', 'integer', 'min:18'],
    ],
);

if (!$result->isValid()) {
    $errors = $result->errors();
    $emailError = $result->error('email');
}

$data = $result->validated();
```

`validated()` contains only fields declared in the rule set that passed all their rules. Optional missing fields and unrelated input are omitted. A present nullable field is retained as `null`.

Use `validateOrFail()` when the request should stop immediately:

```php
$data = $validator->validateOrFail(
    $request->allInput(),
    ['email' => ['required', 'email']],
);
```

Failure throws `Meulah\Validation\ValidationException`. The application exception handler renders it as HTTP `422`; JSON responses include field errors under `error.fields`.

The initial rule set is intentionally small:

- Presence: `required`, `present`, `nullable`
- Types: `string`, `integer`, `boolean`, `array`, `email`
- Size and value: `min`, `max`, `between`, `in`
- Relationships: `same`, `confirmed`
- Uploads: `file`, `max_size`, `detected_mime`

Rules use colon-separated parameters:

```php
[
    'role' => ['in:admin,editor,viewer'],
    'password' => ['confirmed'],
    'password_confirmation' => ['present'],
    'backup_email' => ['same:email'],
]
```

`confirmed` compares a field with `{field}_confirmation`, while `same:other` compares it with another named input field. Comparisons are strict.

For strings, `min`, `max`, and `between` measure Unicode characters; for arrays they measure item count; for integers they compare the numeric value. Canonical form strings are normalized only when an explicit type rule requests it:

- `integer` accepts integers and canonical strings such as `"18"` or `"-2"` and returns an integer.
- `boolean` accepts booleans, `0`, `1`, `"0"`, `"1"`, `"false"`, and `"true"` and returns a boolean.
- Values such as `"twenty"`, `"18 years"`, `"yes"`, empty strings, and arbitrary arrays are never loosely cast.

Uploads are intentionally not part of `allInput()`. Pass selected files explicitly:

```php
$data = $validator->validateOrFail(
    [
        ...$request->allInput(),
        'avatar' => $request->file('avatar'),
    ],
    [
        'avatar' => [
            'required',
            'file',
            'max_size:2097152',
            'detected_mime:image/jpeg,image/png',
        ],
    ],
);
```

`max_size` is an explicit byte limit. `detected_mime` uses server-side file inspection rather than the client-supplied media type. Unknown rules and malformed parameters throw `InvalidArgumentException` immediately because they are programming errors, not user input errors.

## Middleware

Middleware can inspect a request, return a response immediately, or delegate to the next handler. The first registered middleware is the outermost layer:

```php
use Meulah\Http\Middleware;
use Meulah\Http\Request;
use Meulah\Http\RequestHandler;
use Meulah\Http\Response;

final class AddRequestHeader implements Middleware
{
    public function process(Request $request, RequestHandler $next): Response
    {
        $response = $next->handle($request);

        return $response->withHeader('X-Framework', 'Meulah');
    }
}
```

Register middleware for every request in `bootstrap.php`:

```php
$app->middleware(new AddRequestHeader());
```

Or attach middleware to one route:

```php
$router
    ->get('/account', $accountHandler)
    ->middleware(new RequireAuthentication());
```

Middleware must return a `Response`. It may short-circuit the pipeline without calling `$next`, which is useful for authentication, authorization, maintenance mode, CORS preflight, and rate limiting. Exceptions thrown anywhere in the pipeline are rendered by Meulah's configured exception handler.

## Events

The application owns one synchronous event dispatcher and registers it in the container under EventDispatcher:

~~~php
use Meulah\Event\EventDispatcher;

$events = $app->events();
$sameDispatcher = $app->container()->get(EventDispatcher::class);
~~~

Register listeners explicitly against one concrete event class:

~~~php
$events->listen(UserRegistered::class, SendWelcomeEmail::class);
$events->listen(UserRegistered::class, function (UserRegistered $event): void {
    // Additional synchronous work.
});

$event = new UserRegistered($user);
$returned = $events->dispatch($event);
~~~

Listener classes must be invokable. They are resolved through the application container when dispatched, so constructor dependencies and configured singleton lifetimes work normally:

~~~php
final class SendWelcomeEmail
{
    public function __construct(private readonly Mailer $mailer)
    {
    }

    public function __invoke(UserRegistered $event): void
    {
        $this->mailer->sendWelcomeMessage($event->user);
    }
}
~~~

Dispatch is synchronous and listeners run in registration order. Listener return values are ignored, dispatch() returns the same event object, and an exception immediately stops dispatch and propagates to the caller.

Matching is intentionally exact: listeners registered for another class or parent type are not discovered. This version has no queues, event discovery, wildcard listeners, subscribers, realtime broadcasting, annotations, or attributes.

## Configuration

Configuration files live in `config/` and return plain PHP arrays. They are loaded into a small repository with dot-notation and strict typed access:

```php
$environment = $app->config()->string('app.environment');
$database = $app->config()->array('database');
```

Environment-specific values belong in the ignored root `.env`. `.env.example` documents the available variables and is safe to commit.

## Database drivers

Meulah supports MySQL, PostgreSQL, and SQLite through PDO. Select the driver in `.env`:

```ini
DB_DRIVER=mysql
```

Use `pgsql` or `postgresql` for PostgreSQL and `sqlite` for SQLite. Applications create a connection from the loaded configuration:

```php
use Meulah\Database\Connection;

$connection = Connection::fromConfig($app->config()->array('database'));
```

MySQL uses port `3306` by default and PostgreSQL uses `5432`. SQLite reads `DB_PATH`; relative paths are resolved from the project root, while `:memory:` creates an in-memory database. The selected PDO driver (`pdo_mysql`, `pdo_pgsql`, or `pdo_sqlite`) must be installed.

## Migrations

Migration files live in `database/migrations` by default, are ordered by filename, and return an object implementing `Meulah\Database\Migration`:

```php
<?php

use Meulah\Database\Connection;
use Meulah\Database\Migration;

return new class implements Migration {
    public function up(Connection $connection): void
    {
        $connection->execute(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, email VARCHAR(255) NOT NULL)'
        );
    }

    public function down(Connection $connection): void
    {
        $connection->execute('DROP TABLE users');
    }
};
```

Create and manage migrations with the dependency-free CLI:

```bash
php meulah
php meulah list
php meulah help migrate
php meulah migrate --help
php meulah make:migration create_users_table
php meulah migrate
php meulah migrate:status
php meulah migrate:rollback
php meulah migrate:reset
php meulah migrate:fresh
```

The starter's root `meulah` launcher passes its application root directly to the single framework CLI implementation. The framework also exposes `vendor/bin/meulah`; that entry point honors `MEULAH_APPLICATION_ROOT`, searches upward from the current directory, and then checks its Composer installation relationship. Discovery accepts only projects with the starter's explicit `extra.meulah.application` marker and expected bootstrap, configuration, and route structure.

Console features are individual objects implementing `Meulah\Console\Command`. `ConsoleApplication` owns only registration and dispatch, while the launcher composes the built-in migration commands for an application root. `Input` exposes positional arguments and long options and `Output` separates ordinary and error output. Applications can add custom commands without modifying the dispatcher:

```php
use Meulah\Console\Application;

$console = new Application(__DIR__);
$console->add(new ReportCommand());

exit($console->run($argv));
```

An unknown command returns a non-zero status and suggests close registered names. Command execution is non-interactive; options must be supplied explicitly.

`migrate` runs only files not recorded in the migration history table. All migrations from one invocation share a batch number, and `migrate:rollback` reverses the most recent batch in reverse filename order. `migrate:reset` rolls back every recorded batch. `migrate:fresh` drops every table—including tables not managed by migrations—and then reruns all migrations. A recorded migration whose file has been removed appears as `Missing` in the status output.

Use `--path=some/directory` to override the configured directory. `DB_MIGRATIONS` and `DB_MIGRATION_TABLE` configure the defaults. Migration SQL remains intentionally explicit, so applications that support multiple database engines should use SQL compatible with each selected engine.

Rollback, reset, and fresh commands require `--force` when `APP_ENV=production`:

```bash
php meulah migrate:fresh --force
```

Schema migrations are not automatically wrapped in transactions because MySQL implicitly commits many DDL statements. A migration is added to history only after its `up()` method completes successfully.

## Errors and logging

Routing failures are rendered as `404` and `405` responses. Unexpected exceptions are logged through the `Meulah\Log\Logger` interface and rendered by `Meulah\Exception\ExceptionHandler`.

Production responses hide exception details. Development responses include the exception class, message, file, and line, with every dynamic value HTML-escaped. Applications can provide another logger or exception handler explicitly when constructing `Application`.

## Installation

Application developers should use the separate [Meulah application starter](https://github.com/Meulah/meulah). Once the `0.1` packages are published, the normal installation path is:

```bash
composer create-project meulah/starter my-app
```

The repository split is complete. Publishing compatible `0.1` releases for `meulah/framework` and `meulah/starter` remains the release gate before advertising `create-project` as generally available.

Custom skeleton authors and advanced integrations may install the framework directly:

```bash
composer require meulah/framework:^0.1
```

Framework contributors run `composer install` and `composer test` at this repository root. Application bootstrapping, `create-project`, and clean-consumer installation are owned and tested by the starter repository.

## Tests

Run the dependency-free kernel tests with:

```bash
composer test
```

or:

```bash
php tests/run.php
```

The GitHub Actions workflow validates, lints, and tests the framework on PHP 8.1 and 8.5. Framework tests use a minimal marked application fixture to verify CLI root discovery without depending on the external starter repository.

## Application ownership

The framework package contains only reusable kernel behavior. Authentication, user models, mail delivery, UUID generation, and application-specific views are intentionally not bundled. Applications install optional packages and define those features according to their own needs.

All framework implementation lives in the namespaced `src` tree. The external starter offers one recommended application layout, but the kernel still depends only on Composer namespaces and explicit bootstrap configuration.
