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
Route methods must be valid HTTP token strings; empty values, control characters, and header-like method text are rejected when routes are registered. Route paths cannot contain control characters, query strings, or fragments.

Route registration is deterministic. A second registration with an overlapping method and the same normalized path throws `RouteDefinitionException`; GET registrations include HEAD for this check. Duplicate names also fail instead of replacing the earlier route. When both a static and dynamic route match the same method, static routes are evaluated first regardless of declaration order. Routes of the same kind retain declaration order.

One trailing slash is normalized away, including across nested group boundaries. Repeated internal slashes remain distinct. Request paths are percent-decoded exactly once before routing, so an encoded slash becomes a path separator; encode a literal percent sign when `%2F` must remain segment data. `Request::capture()` removes the installed application subdirectory before dispatch.

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
Constraint fragments are validated when registered and matched against the entire decoded path segment with `\A` and `\z` anchors. Ordinary capture groups are allowed, but named captures are rejected because they can collide with Meulah's internal route-parameter captures. Meulah selects a delimiter absent from the fragment rather than rewriting user regular expressions.

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

Unknown paths return `404 Not Found`. A known path requested with an unsupported HTTP method returns `405 Method Not Allowed` with a stable, de-duplicated `Allow` header. GET always adds HEAD, and HEAD responses have their body removed. OPTIONS is explicit: register an `options()` route when an endpoint handles it; otherwise a matching path returns 405 and advertises its registered methods.

Controller array handlers and invokable class strings must resolve to public callables. The first parameter must be typed exactly as `Request` to receive request injection. Decoded route parameters may target untyped, `string`, or `mixed` handler parameters; scalar coercion, union/intersection guessing, and by-reference request or route arguments are rejected with `RouteHandlerException` context.

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

The `X-HTTP-Method-Override` header is also supported for clients that cannot send those methods directly. Overrides are considered only when the original method is `POST`, and only `PUT`, `PATCH`, or `DELETE` are accepted. Query-string overrides are ignored. Regular ASCII spaces around a string value are allowed; controls, empty values, arrays, objects, comma-separated method lists, and duplicate override headers produce `400 invalid_method_override`.

When both the form field and header are present, their normalized values must match. Matching values are accepted deliberately; conflicts produce `400`. Overrides on GET or any other original method are ignored, even when an `_method` field is present. The effective method is resolved while constructing the request, before CSRF middleware and routing. Use `originalMethod()` for the transport method and `method()` for the effective method.
Original request methods must also be valid HTTP tokens. Invalid method text, non-string server path metadata, malformed `Content-Length` values, and control characters in decoded request paths produce a `400` response before routing.

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
A request-body read failure is reported as `request_body_unavailable`; it is never silently treated as an empty body.

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
    domain: 'example.com',
    maxAge: 2_592_000,
);

return Response::html('Saved')->withCookie($cookie);
```

`withCookie()` returns a new response. Multiple calls retain multiple cookies, including duplicate names with different paths, and `send()` emits each one as a separate `Set-Cookie` header without replacing unrelated `Set-Cookie` headers. Cookie values are percent-encoded, so Unicode and reserved value characters are serialized rather than copied into the header. Raw control characters are rejected.

Cookie names must use HTTP token syntax. Paths must begin with `/` and use visible ASCII without spaces or semicolons; percent-encode non-ASCII URL paths before using them. Domains are optional, ASCII-only host names; convert internationalized names to punycode before passing them. Negative `Max-Age`, unsupported SameSite values, `SameSite=None` without `Secure`, invalid `__Secure-` or `__Host-` attributes, and expiration years outside 1601 through 9999 are rejected. Use `Cookie::forget('session', path: '/', domain: 'example.com')` to produce an explicit `Expires` plus `Max-Age=0` deletion cookie. `send()` fails explicitly if PHP has already committed response headers.

Sessions remain an explicit application choice. Bind the contract to the native PHP driver in application bootstrap when session state is needed:

```php
use Meulah\Http\SameSite;
use Meulah\Session\NativeSession;
use Meulah\Session\Session;
use Meulah\Session\SessionMiddleware;

$secureCookies = $app->config()->bool('session.secure', true);

$container->singleton(
    Session::class,
    static fn () use ($secureCookies): Session => new NativeSession(
        name: 'MEULAHSESSID',
        secure: $secureCookies,
        httpOnly: true,
        sameSite: SameSite::Lax,
    ),
);

$session = $container->get(Session::class);
$app->middleware(new SessionMiddleware($session));
```

`NativeSession` starts lazily on the first session operation; `SessionMiddleware` does not force a start and always closes the session after downstream handling, including exceptions. Startup enables strict session IDs, cookie-only transport, disabled URL ID propagation, explicit cookie attributes, and validation of attacker-controlled identifiers. Unknown IDs are rejected by PHP strict mode. Invalid ID syntax is discarded without exposing native warnings.

Bind one `NativeSession` instance per application request when possible. Two wrappers cannot manage the same active session, and unmanaged active native sessions are rejected. On `close()`, Meulah writes the session and clears `$_SESSION` plus the process-local session ID, preventing a retained object in a long-running worker from inheriting the previous request's state. The next start selects the incoming session cookie explicitly.

```php
$user = $session->get('user');
$session->put('user', $user);
$session->remove('temporary');
$session->regenerate();
$session->invalidate();
$session->flash('notice', 'Profile saved.');
$session->keep('notice');
$session->reflash();
```

`regenerate()` rotates the identifier while preserving ordinary and flash data. Call it immediately after authentication or another authentication-sensitive privilege change to prevent fixation. `invalidate()` clears all data, rotates the identifier, and emits an explicit deletion cookie for the browser. Both operations fail before invoking PHP when response output has already started.

Flash lifecycle is request-based and deterministic:

- `flash()` creates new flash data that is readable immediately and during the next request.
- At the start of that next request it becomes old flash data.
- At the start of the following request it is removed unless `keep()` retained selected keys or `reflash()` retained all old keys.
- Writing an ordinary value with `put()` removes that key's flash marker. Repeated `flash()` calls overwrite the value without duplicating lifecycle metadata.

Secure cookies remain the default. Production should use HTTPS, `secure: true`, `httpOnly: true`, `SameSite::Lax` or `SameSite::Strict`, and should omit `domain` unless subdomain sharing is required. For local plain-HTTP development, set an explicit local configuration value such as `session.secure => false`; Meulah never weakens Secure automatically because the host looks local. `SameSite::None` always requires Secure.

Only the native PHP driver exists in this milestone. Its persistence is controlled by PHP's configured session save handler. File, database, and Redis drivers remain future adapters behind the same `Session` contract.

## Authentication contracts

Meulah provides model-agnostic authentication contracts and one session-backed guard. It does not provide a User model, table layout, credential fields, password hashing workflow, or ORM-backed provider.

An application identity implements only `Authenticatable`:

```php
use Meulah\Auth\Authenticatable;

final class User implements Authenticatable
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
    ) {
    }

    public function authIdentifier(): string
    {
        return (string) $this->id;
    }
}
```

Authentication identifiers are deliberately non-empty strings. Session serialization therefore preserves identifiers such as `"0"`, `"00042"`, UUIDs, and Unicode identifiers without guessing an integer width or silently changing representation. Applications with integer primary keys cast them explicitly at their boundary. Meulah does not trim or otherwise normalize identifiers.

The first `UserProvider` contract restores only by that stable identifier:

```php
use Meulah\Auth\Authenticatable;
use Meulah\Auth\UserProvider;

final class ApplicationUserProvider implements UserProvider
{
    public function retrieveById(string $identifier): ?Authenticatable
    {
        // Query an application repository and return its User, or null.
    }
}
```

Meulah does not provide an ORM-backed implementation because table names, key types, tenancy, soft deletion, persistence libraries, and identity lifecycles are application policy. The contract works equally with an ORM, PDO-backed repository, remote service, or in-memory fake.

Credential lookup and password verification do not belong in this contract yet. Adding `retrieveByCredentials()` would prematurely define credential-field and secret-handling conventions before an `attempt()` workflow exists. Applications remain free to build an explicit login service around their own repository and password hasher, then pass the verified identity to `Guard::login()`.

Bind the application provider, the request's session, and the guard explicitly:

```php
use Meulah\Auth\Guard;
use Meulah\Auth\SessionGuard;
use Meulah\Auth\UserProvider;
use Meulah\Session\Session;

$container = $app->container();
$container->instance(Session::class, $session);
$container->singleton(UserProvider::class, ApplicationUserProvider::class);
$container->singleton(Guard::class, SessionGuard::class);

$guard = $container->get(Guard::class);
```

`SessionGuard` stores only the string identifier, never the entire user object. `user()` restores lazily through the provider; a missing record is ordinary guest state. Invalid stored identifiers or a provider returning a different identifier throw `InvalidAuthenticatableException` without exposing either value. The in-memory result is tied to the current session ID, so session regeneration, invalidation, or request reuse causes authentication state to be resolved again.

`login()` rotates the session ID before storing the identifier. `logout()` removes only authentication state, rotates the session ID, and preserves unrelated session data. Because CSRF tokens are bound to the session ID, both transitions also make the previous CSRF token stale. Applications should bind one guard per request; there is no global or static authentication state.

No authentication manager is included because Meulah currently has one guard type and no demonstrated named-guard requirement. Also postponed are `attempt()`, credential validation, password hash contracts, authentication middleware, unauthenticated HTTP exceptions, login or registration controllers, remember-me cookies, authorization, API tokens, OAuth, and JWT.

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

Tokens are generated from 32 cryptographically random bytes and encoded as exactly 64 lowercase hexadecimal characters. The encoded token and a SHA-256 fingerprint of the session identifier are stored in the session. Supplied and stored values must have the expected shape before comparison with `hash_equals()`.

The token remains valid across ordinary requests while the session identifier is unchanged. Regenerating the session identifier immediately invalidates the old token; the next call to `token()` or `field()` creates its replacement. `invalidate()` clears the token and rotates the session, so a token captured before logout cannot be replayed. `isValid()` is read-only, and malformed tokens are rejected before session access.

`VerifyCsrfToken` requires a valid token for every method except GET, HEAD, and OPTIONS. This includes POST, PUT, PATCH, DELETE, and custom unsafe methods supported by the router. Method spoofing happens first, so CSRF checks use the effective method while `originalMethod()` remains available for diagnostics.

JavaScript clients may send the token through the documented header:

~~~text
X-CSRF-Token: <token>
~~~

For URL-encoded and multipart requests, the middleware reads the parsed `_token` form field. JSON requests must use `X-CSRF-Token`; a token embedded in raw JSON is deliberately ignored. If both supported sources are present, each must be valid and their values must match. Null, array, object, truncated, oversized, malformed, stale, or conflicting values produce a 419 response without exposing the submitted or stored token.

HTML clients receive a safe `Page Expired` response. JSON clients receive the stable `csrf_token_mismatch` error code. A malformed JSON body with a valid header proceeds to normal request parsing and may produce `400 invalid_json`; without a valid CSRF token, the security check fails first with 419.

Exclusions are exact normalized paths:

~~~php
new VerifyCsrfToken($session, except: [
    '/webhooks/payment-provider',
]);
~~~

Exclusions are matched case-sensitively against the application-relative request path after one percent-decoding and the framework's documented path normalization. Query strings are removed during request capture, a configured trailing slash is normalized consistently, and an application subdirectory is stripped. Duplicate internal slashes remain distinct, preventing them from silently becoming an excluded path.

Wildcards, route parameters, prefixes, query-string conditions, and malformed percent escapes are rejected, including percent-encoded forms of reserved exclusion syntax. Each excluded endpoint must therefore be an explicit security decision.

## Validation

Validation produces separate application data and never mutates the request or source array. `Validator` remains the public entry point; rule parsing and evaluation are isolated internally so malformed rule definitions fail before user data is evaluated.

```php
use Meulah\Validation\Validator;

$result = $validator->validate(
    $request->allInput(),
    [
        'name' => ['required', 'string', 'min:2', 'max:100'],
        'email' => ['required', 'string', 'email'],
        'age' => ['nullable', 'integer', 'min:18'],
    ],
);

if (!$result->isValid()) {
    $errors = $result->errors();
    $emailError = $result->error('email');
}

$data = $result->validated();
```

`validated()` contains only declared fields that passed every applicable rule. Optional missing fields, unrelated input, and invalid fields are omitted. Validation has no default-value feature: a missing value remains missing, and an invalid value is never replaced.

Use `validateOrFail()` when validation should stop request handling:

```php
$data = $validator->validateOrFail(
    $request->allInput(),
    ['email' => ['required', 'string', 'email']],
);
```

Failure throws `Meulah\Validation\ValidationException` with HTTP status `422`. JSON requests receive stable field errors under `error.fields`; HTML requests receive a generic response without submitted values. Error messages never include submitted values, client MIME values, or temporary upload paths.

### Presence and nullability

Presence and validity are separate concepts:

| Input state | `required` | `present` | `nullable` |
|---|---|---|---|
| Field absent | fails | fails | field remains omitted |
| `null` | fails | passes | retains `null` and skips later rules |
| Empty string | fails | passes | not treated as null |
| Whitespace-only string | passes | passes | unchanged |
| `0`, `"0"`, or `false` | passes | passes | unchanged or explicitly type-normalized |
| Empty array | fails | passes | not treated as null |

`required` considers only `null`, `''`, and `[]` empty. It does not trim strings. Use `present` plus `nullable` when a key must exist but may contain `null`; `required` and `nullable` are rejected as a conflicting rule set.

A missing field is not evaluated by ordinary type, size, comparison, or file rules. A present `null` value without `nullable` is evaluated normally and usually fails its type rules.

### Rule parsing and execution

Rules are declared as a list of strings. Rule names are case-insensitive, while parameters remain exact and are not silently trimmed or lowercased.

```php
[
    'role' => ['in:admin,editor,viewer'],
    'password' => ['confirmed'],
    'backup_email' => ['same:email'],
]
```

The parser rejects:

- unknown, empty, or non-string rules;
- associative rule arrays instead of lists;
- duplicate rule names, including duplicates with different casing;
- missing, extra, duplicated, overflowing, or malformed parameters;
- surrounding whitespace in `same` field names and MIME parameters;
- multiple incompatible type rules;
- `required` combined with `nullable`;
- `email` combined with a non-string type;
- file metadata rules combined with a non-file type;
- size rules combined with boolean or file types.

Malformed definitions throw `Meulah\Validation\ValidationRuleException` with the field, rule name, and rule position where useful. Submitted values are never included.

Fields retain declaration order in `validated()` and `errors()`. Errors for a field retain declared rule order. All applicable rules are evaluated, except:

- a failed `required` rule produces one required error and stops that field;
- a present `null` with `nullable` is accepted and stops that field;
- a missing field is checked only for `required` and `present`.

Type normalization happens before rule evaluation and does not depend on where the type rule appears in the rule list.

### Integer and boolean normalization

`integer` accepts native integers and canonical base-10 strings within the current PHP platform integer range:

- accepted: `18`, `"18"`, `"-18"`, `"0"`, and `"-0"`;
- rejected: `"+18"`, `"018"`, decimals, scientific notation, hexadecimal-like strings, surrounding whitespace, booleans, floats, arrays, objects, and out-of-range values.

Accepted integer strings become native integers in validated data.

`boolean` accepts only these exact values:

- true: `true`, `1`, `"1"`, `"true"`;
- false: `false`, `0`, `"0"`, `"false"`.

Mixed case, surrounding whitespace, `yes`, `no`, `on`, `off`, floats, arrays, and objects are rejected. Accepted forms become native booleans.

### Strings, email, arrays, and size

`string` accepts only native PHP strings. It does not trim, lowercase, normalize Unicode, or otherwise alter the value.

For strings, `min`, `max`, and `between` count Unicode code points through PCRE, so `mbstring` is not required. A combining sequence can count as multiple code points even when displayed as one grapheme. Invalid UTF-8 cannot satisfy a string size rule.

`email` uses PHP's strict email filter on the original string. Leading or trailing whitespace is rejected, case is preserved, and internationalized domains are not converted to ASCII automatically.

`array` accepts indexed, associative, empty, and nested PHP arrays. Objects and `Traversable` values are not converted. Array size rules count only the top-level number of elements.

`min`, `max`, and `between` support:

- native or explicitly normalized integers by numeric value;
- strings by Unicode code-point count;
- arrays by top-level item count.

Native floats and unsupported value types fail size rules. Numeric rule parameters must be canonical finite decimal values.

### Strict comparisons

`same:other` fails when the comparison field is missing. It compares both fields with strict equality after each declared field has undergone its own explicit type normalization.

`confirmed` compares a field with `{field}_confirmation`. A missing confirmation fails. The confirmation value is normalized using the subject field's integer or boolean rule before strict comparison.

`in` also uses strict equality. Allowed parameters are converted only when the validated value has an accepted integer or boolean type. For example, integer `1` matches `in:1` but does not match `in:01`. Error output says only that the value is not allowed; it does not echo the configured list.

### File validation

Uploads are not merged into `Request::allInput()`. Add selected files explicitly:

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

`file` accepts only a valid, unmoved `UploadedFile`. Missing optional files remain omitted; invalid uploads, moved files, vanished temporary files, ordinary arrays, and arrays of uploads fail.

`max_size` uses an inclusive byte boundary, so a five-byte file passes `max_size:5`. Zero-byte files can pass `max_size:0`. The limit must fit the platform integer range.

`detected_mime` compares exact server-detected MIME types case-insensitively. Client filenames and client MIME declarations are ignored. MIME parameters, wildcards, whitespace, and duplicate MIME values are rejected. Detection and validity failures produce safe errors without filesystem paths.

The first rule catalogue remains intentionally small: `required`, `present`, `nullable`, `string`, `integer`, `boolean`, `array`, `email`, `min`, `max`, `between`, `in`, `same`, `confirmed`, `file`, `max_size`, and `detected_mime`. Database-aware rules, DTO hydration, and implicit string normalization remain outside this layer.
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

Dispatch is synchronous and listeners run in registration order. Registering the same closure, callable, or listener class more than once is deliberate: each registration produces one invocation. A listener may require at most the event argument, cannot receive it by reference, and its declared type must accept the registered event class. Incompatible signatures are rejected during registration.

Listener return values are ignored, event mutation is visible to later listeners, and `dispatch()` returns the same event object. An exception immediately stops dispatch and propagates unchanged. Nested dispatch of another event is supported; recursively dispatching the same event object throws `EventDispatchException`, and the in-progress marker is always cleared so a long-running dispatcher retains no per-dispatch state.

Registration matching is intentionally exact: dispatching a child class does not discover listeners registered for its parent or interfaces. A listener registered directly for that child may still type its parameter as a compatible parent, interface, `object`, or `mixed`. This version has no queues, event discovery, wildcard listeners, subscribers, priorities, realtime broadcasting, annotations, or attributes.

Treat listener registration as application-lifetime configuration. In a long-running worker, do not add request-specific closures to the shared dispatcher; registered listeners intentionally remain until the dispatcher itself is discarded.

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

Console features are individual objects implementing `Meulah\Console\Command`. `ConsoleApplication` owns only registration and dispatch, while the launcher composes the built-in migration commands for an application root. Command names and aliases are unique, help output is sorted by command name, and equally close unknown-command suggestions are sorted by name.

`Input` preserves positional arguments and parses long options without coercion. Values use `--option=value`; `--option value` is not supported, and a bare `--option` is the boolean flag `true`. Empty values remain distinct from missing options. Duplicate options are rejected. Built-in commands reject unknown options, surplus positional arguments, valued flags, and options combined with command help. Custom commands should call `assertOnlyOptions()`, `assertArgumentCount()`, and `assertFlag()` before side effects. `Output` is the only output path used by commands, separates stdout from stderr, and reports stream failures explicitly. Applications can add custom commands without modifying the dispatcher:

```php
use Meulah\Console\Application;

$console = new Application(__DIR__);
$console->add(new ReportCommand());

exit($console->run($argv));
```

Unknown commands, invalid input, and thrown command exceptions write to stderr and return status `1`; command return codes otherwise pass through unchanged. Command execution is non-interactive and never prompts in CI.

`migrate` runs only files not recorded in the migration history table. All migrations from one invocation share a batch number, and `migrate:rollback` reverses the most recent batch in reverse filename order. `migrate:reset` rolls back every recorded batch. `migrate:fresh` drops every table—including tables not managed by migrations—and then reruns all migrations. A recorded migration whose file has been removed appears as `Missing` in the status output.

Use `--path=some/directory` to override the configured directory; `--path some/directory`, bare `--path`, and `--path=` are invalid, while Unix `/` and Windows drive roots are preserved exactly. `DB_MIGRATIONS` and `DB_MIGRATION_TABLE` configure the defaults. Migration SQL remains intentionally explicit, so applications that support multiple database engines should use SQL compatible with each selected engine.

Rollback, reset, and fresh commands require a bare `--force` when `APP_ENV=production`; `--force=` and `--force=false` are rejected in every environment:

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
