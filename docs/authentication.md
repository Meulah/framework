# Authentication and authorization integration

Meulah supplies authentication and authorization boundaries, not an application identity system. The framework owns session-backed identity restoration, password hashing primitives, middleware, Gate evaluation, CSRF verification, and safe default HTTP denials. The application owns its user model, credential lookup, password policy, persistence, login and logout actions, and all ability definitions.

## Application identity and provider

An application user exposes only a stable non-empty string identifier to the framework:

~~~php
use Meulah\Auth\Authenticatable;

final class User implements Authenticatable
{
    public function __construct(
        private readonly string $id,
        public readonly string $email,
        private readonly string $passwordHash,
    ) {
    }

    public function authIdentifier(): string
    {
        return $this->id;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }
}
~~~

Do not place the password hash on Authenticatable. It is application credential data, not part of the framework identity contract.

UserProvider restores an identity only from the identifier stored in the session. Credential lookup remains a separate application interface:

~~~php
use Meulah\Auth\Authenticatable;
use Meulah\Auth\UserProvider;

interface CredentialUserLookup
{
    public function findByEmail(string $email): ?User;
}

final class ApplicationUserProvider implements UserProvider, CredentialUserLookup
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function retrieveById(string $identifier): ?Authenticatable
    {
        return $this->users->findById($identifier);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->users->findByEmail($email);
    }
}
~~~

The repository may use PDO, an ORM, a remote identity service, or an in-memory implementation. Meulah does not prescribe email, username, table names, tenancy, soft deletion, or credential columns.

## Request-scoped container setup

Create the stateful authentication graph for one request. This example uses one explicit Guard instance so authentication middleware, Gate, and controllers share the same request-local user resolution:

~~~php
use Meulah\Application;
use Meulah\Auth\Guard;
use Meulah\Auth\NativePasswordHasher;
use Meulah\Auth\PasswordHasher;
use Meulah\Auth\SessionGuard;
use Meulah\Auth\UserProvider;
use Meulah\Authorization\AuthorizationGate;
use Meulah\Authorization\AuthorizationResult;
use Meulah\Authorization\Gate;
use Meulah\Container\Container;
use Meulah\Routing\Router;
use Meulah\Session\NativeSession;
use Meulah\Session\Session;

$container = new Container();
$router = new Router($container);
$app = new Application($router);

$session = new NativeSession(
    secure: true,
    httpOnly: true,
);
$provider = new ApplicationUserProvider($userRepository);
$guard = new SessionGuard(
    $session,
    $provider,
    '_auth_user',
);

$container->instance(Session::class, $session);
$container->instance(UserProvider::class, $provider);
$container->instance(CredentialUserLookup::class, $provider);
$container->instance(Guard::class, $guard);
$container->singleton(
    PasswordHasher::class,
    static fn (): PasswordHasher => new NativePasswordHasher(
        PASSWORD_DEFAULT,
    ),
);

$gate = new AuthorizationGate($guard, $container);
$gate->define(
    'user.edit',
    static function (User $actor, string $target): AuthorizationResult {
        return $actor->authIdentifier() === $target
            ? AuthorizationResult::allow()
            : AuthorizationResult::deny();
    },
);
$container->instance(Gate::class, $gate);
~~~

NativePasswordHasher is safe as a singleton because it stores only immutable algorithm configuration. Session, SessionGuard, AuthorizationGate, authentication middleware, authorization middleware, and controllers that capture them are request state and must not be process-global.

Meulah does not currently have a request scope. Under PHP-FPM or another request-per-bootstrap model, the process naturally rebuilds this graph. A long-running worker must explicitly rebuild the Container, Session, Guard, Gate, Router, and route middleware graph for every request. Rebinding only Guard is insufficient because an existing router retains the middleware objects registered on its routes.

## Explicit login and logout actions

A login action performs application validation, application credential lookup, native password verification, then calls Guard::login():

~~~php
use Meulah\Auth\Guard;
use Meulah\Auth\PasswordHasher;
use Meulah\Http\Request;
use Meulah\Http\Response;
use Meulah\Http\ResponseInterface;

final class LoginAction
{
    public function __construct(
        private readonly CredentialUserLookup $users,
        private readonly PasswordHasher $passwords,
        private readonly Guard $guard,
        private readonly string $dummyHash,
    ) {
    }

    public function __invoke(Request $request): ResponseInterface
    {
        $email = $request->form('email');
        $plainText = $request->form('password');

        if (!is_string($email) || !is_string($plainText)) {
            return $this->rejected();
        }

        $user = $this->users->findByEmail($email);
        $hash = $user?->passwordHash() ?? $this->dummyHash;
        $valid = $this->passwords->verify($plainText, $hash);

        if (!$valid || $user === null) {
            return $this->rejected();
        }

        $this->guard->login($user);

        return Response::redirect('/account', 303);
    }

    private function rejected(): ResponseInterface
    {
        return Response::html('The supplied credentials are invalid.', 401);
    }
}
~~~

Generate the dummy hash once during deployment or application configuration; do not hash a dummy password on every request. Using it keeps unknown-user and wrong-password paths closer in cost. Always return the same safe rejection for both. Never place plaintext passwords, password hashes, or supplied credentials in logs, exception messages, flash data, URLs, or authorization results.

Guard::login() rotates the session identifier before storing the stable user identifier. It preserves unrelated session and flash data. Because the CSRF token is bound to the session identifier, any pre-login token becomes stale and the next rendered form must use a new token.

A standard logout removes only authentication state and rotates the identifier:

~~~php
use Meulah\Auth\Guard;
use Meulah\Http\Response;
use Meulah\Http\ResponseInterface;

final class LogoutAction
{
    public function __construct(private readonly Guard $guard)
    {
    }

    public function __invoke(): ResponseInterface
    {
        $this->guard->logout();

        return Response::redirect('/login', 303);
    }
}
~~~

Guard::logout() preserves unrelated session data. An application requiring a full logout must explicitly call Session::invalidate() as its policy. Both operations rotate session identity and make the previous CSRF token stale. Render a new token after login or logout.

## Middleware, routes, and abilities

Register session and CSRF middleware globally in this order:

~~~php
use Meulah\Security\Csrf\VerifyCsrfToken;
use Meulah\Session\SessionMiddleware;

$app->middleware(
    new SessionMiddleware($session),
    new VerifyCsrfToken($session),
);
~~~

The login form is an unsafe session-backed request and must include the CSRF field. Login is guest-only, account and logout routes require authentication, and authorization follows authentication:
Because the login action receives a precomputed dummy hash, register its scalar configuration with the explicit container factory shown below rather than asking the container to guess it.


~~~php
use Meulah\Auth\Guard;
use Meulah\Auth\PasswordHasher;
use Meulah\Container\Container;
use Meulah\Auth\RequireAuthentication;
use Meulah\Auth\RequireGuest;
use Meulah\Authorization\Authorize;
use Meulah\Http\Request;
use Meulah\Http\Response;
use Meulah\Http\ResponseInterface;
use Meulah\Routing\RouteParameters;

$authenticated = new RequireAuthentication(
    $guard,
    static fn (Request $request): ResponseInterface =>
        Response::redirect('/login'),
);
$guest = new RequireGuest(
    $guard,
    static fn (Request $request): ResponseInterface =>
        Response::redirect('/account'),
);
$canEditUser = new Authorize(
    $gate,
    'user.edit',
    static fn (Request $request, RouteParameters $parameters): array => [
        $parameters->require('user'),
    ],
);

$container->bind(
    LoginAction::class,
    static fn (Container $container): LoginAction => new LoginAction(
        $container->get(CredentialUserLookup::class),
        $container->get(PasswordHasher::class),
        $container->get(Guard::class),
        $dummyHash,
    ),
);
$router->get('/login', [LoginController::class, 'show'])
    ->middleware($guest);
$router->post('/login', LoginAction::class)
    ->middleware($guest);
$router->get('/account', AccountAction::class)
    ->middleware($authenticated);
$router->patch('/users/{user}', EditUserAction::class)
    ->middleware($authenticated, $canEditUser);
$router->post('/logout', LogoutAction::class)
    ->middleware($authenticated);
~~~

The effective order is:

1. SessionMiddleware
2. VerifyCsrfToken for unsafe requests
3. RequireAuthentication or RequireGuest
4. Authorize when the route has an ability
5. the controller or action

Unauthenticated handling belongs to authentication middleware and may redirect or return 401. Authenticated authorization denial returns a safe 403 by default. The default Authorize response never exposes AuthorizationResult messages or codes.

## Security and lifecycle checklist

- Keep secure: true, httpOnly: true, and an explicit SameSite policy in production.
- Register CSRF middleware for every session-backed unsafe route, including login and logout.
- Use generic invalid-credential responses and an application-owned dummy hash strategy.
- Apply password length and acceptance policy before hashing; the hasher deliberately does not trim or normalize.
- Regenerate the session identifier on login and logout. Do not reuse tokens rendered before either transition.
- A stale or deleted provider identity becomes a guest. SessionGuard deliberately leaves the stale identifier in the session until the application logs out or invalidates it.
- Do not register SessionGuard, AuthorizationGate, Authorize, or authentication middleware as process-global singletons.
- Do not reuse a request's SessionGuard across worker requests. Its resolved user cache is intentionally request-local.
- Do not capture a request user, request, session, or mutable tenant context in Gate ability closures stored beyond that request.
- Keep production exception debug output disabled. Application exceptions and logs must not contain plaintext credentials or password hashes.
- Treat custom authorization denial callbacks as an explicit disclosure boundary.
- Provider exceptions propagate. Applications should make repository failures safe to log and let the production exception boundary return a generic 500.
- Configure local HTTP session cookies explicitly; Meulah never disables Secure automatically based on the hostname.

## Application-owned features

The application remains responsible for:

- user persistence and schema;
- credential lookup and validation;
- password length, complexity, and rehash persistence policy;
- generic login failure behavior and timing mitigation;
- login throttling and abuse monitoring;
- login, logout, registration, and account actions or controllers;
- deciding whether logout preserves or invalidates all session data;
- ability names and business authorization decisions;
- safe handling of authorization denial details;
- long-running worker request scoping;
- audit logging that excludes credentials and sensitive business data.

Meulah intentionally does not include authentication scaffolding, remember-me cookies, password reset, roles, permission tables, policies, API tokens, OAuth, JWT, MFA, or rate limiting.

