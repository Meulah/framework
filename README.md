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

## Configuration

Configuration files live in `config/` and return plain PHP arrays. They are loaded into a small repository with dot-notation and strict typed access:

```php
$environment = $app->config()->string('app.environment');
$database = $app->config()->array('database');
```

Environment-specific values belong in the ignored root `.env`. `.env.example` documents the available variables and is safe to commit.

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
