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

## Request lifecycle

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

Unknown paths return `404 Not Found`. A known path requested with an unsupported HTTP method returns `405 Method Not Allowed` with an `Allow` header.

`Meulah\Http\Response` is the default implementation of `ResponseInterface`. Routing, middleware, request handlers, and the application kernel depend on the interface, so applications may return another compatible response implementation when needed.

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
php meulah make:migration create_users_table
php meulah migrate
php meulah migrate:status
php meulah migrate:rollback
php meulah migrate:reset
php meulah migrate:fresh
```

The root `meulah` launcher delegates to the internal `bin/meulah` executable. `migrate` runs only files not recorded in the migration history table. All migrations from one invocation share a batch number, and `migrate:rollback` reverses the most recent batch in reverse filename order. `migrate:reset` rolls back every recorded batch. `migrate:fresh` drops every table—including tables not managed by migrations—and then reruns all migrations. A recorded migration whose file has been removed appears as `Missing` in the status output.

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

1. Copy `.env.example` to `.env` and set local credentials.
2. Run `composer install` from the repository root.
3. Point the web server document root at `public/`, or use the included Apache rewrite rules during local development.

## Tests

Run the dependency-free kernel tests with:

```bash
composer test
```

or:

```bash
php tests/run.php
```

## Direction

The repository contains only the reusable framework kernel. Authentication, user models, mail delivery, UUID generation, and application views are intentionally not bundled. Applications install optional packages and define those features according to their own needs.

All framework implementation now lives in the namespaced `src` tree. Application code can organize its own controllers, models, and views without those directories being requirements of the framework.

The next milestone will provide a separate application skeleton instead of mixing sample application code into the kernel.
