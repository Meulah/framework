<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Meulah\Application;
use Meulah\Auth\Authenticatable;
use Meulah\Auth\Guard;
use Meulah\Auth\PasswordHasher;
use Meulah\Auth\RequireAuthentication;
use Meulah\Auth\RequireGuest;
use Meulah\Auth\SessionGuard;
use Meulah\Auth\UserProvider;
use Meulah\Authorization\AuthorizationGate;
use Meulah\Authorization\AuthorizationResult;
use Meulah\Authorization\Authorize;
use Meulah\Authorization\Gate;
use Meulah\Container\Container;
use Meulah\Exception\ExceptionHandler;
use Meulah\Http\Request;
use Meulah\Http\Response;
use Meulah\Http\ResponseInterface;
use Meulah\Log\Logger;
use Meulah\Routing\RouteParameters;
use Meulah\Routing\Router;
use Meulah\Security\Csrf\Csrf;
use Meulah\Security\Csrf\VerifyCsrfToken;
use Meulah\Session\Session;
use Meulah\Session\SessionMiddleware;
use RuntimeException;
use Throwable;

final class TestUser implements Authenticatable
{
    /** @param list<string> $editableUsers */
    public function __construct(
        private readonly string $identifier,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly array $editableUsers = [],
    ) {
    }

    public function authIdentifier(): string
    {
        return $this->identifier;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function canEdit(string $identifier): bool
    {
        return in_array($identifier, $this->editableUsers, true);
    }
}

interface TestCredentialLookup
{
    public function findByEmail(string $email): ?TestUser;
}

final class InMemoryUserProvider implements UserProvider, TestCredentialLookup
{
    /** @var array<string, TestUser> */
    private array $usersById = [];

    /** @var array<string, TestUser> */
    private array $usersByEmail = [];

    /** @var list<string> */
    public array $retrievedIdentifiers = [];

    /** @var list<string> */
    public array $credentialLookups = [];

    private ?string $retrievalFailure = null;

    public function add(TestUser $user): void
    {
        $this->usersById[$user->authIdentifier()] = $user;
        $this->usersByEmail[$user->email()] = $user;
    }

    public function delete(string $identifier): void
    {
        $user = $this->usersById[$identifier] ?? null;
        unset($this->usersById[$identifier]);

        if ($user !== null) {
            unset($this->usersByEmail[$user->email()]);
        }
    }

    public function failRetrievalWith(?string $message): void
    {
        $this->retrievalFailure = $message;
    }

    public function retrieveById(string $identifier): ?Authenticatable
    {
        $this->retrievedIdentifiers[] = $identifier;

        if ($this->retrievalFailure !== null) {
            throw new RuntimeException($this->retrievalFailure);
        }

        return $this->usersById[$identifier] ?? null;
    }

    public function findByEmail(string $email): ?TestUser
    {
        $this->credentialLookups[] = $email;
        return $this->usersByEmail[$email] ?? null;
    }
}

final class IntegrationSessionStore
{
    /** @var array<string, array<string, mixed>> */
    private array $sessions = [];

    private int $sequence = 0;

    /** @param array<string, mixed> $data */
    public function create(array $data = []): string
    {
        $identifier = 'integration-session-' . ++$this->sequence;
        $this->sessions[$identifier] = $data;

        return $identifier;
    }

    public function open(?string $identifier): string
    {
        if ($identifier !== null && isset($this->sessions[$identifier])) {
            return $identifier;
        }

        return $this->create();
    }

    public function get(string $identifier, string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->sessions[$identifier])
            ? $this->sessions[$identifier][$key]
            : $default;
    }

    public function put(string $identifier, string $key, mixed $value): void
    {
        $this->sessions[$identifier][$key] = $value;
    }

    public function remove(string $identifier, string $key): void
    {
        unset($this->sessions[$identifier][$key]);
    }

    public function regenerate(string $identifier, bool $preserve): string
    {
        $data = $preserve ? ($this->sessions[$identifier] ?? []) : [];
        unset($this->sessions[$identifier]);

        return $this->create($data);
    }

    /** @return array<string, mixed> */
    public function data(string $identifier): array
    {
        return $this->sessions[$identifier] ?? [];
    }

    public function exists(string $identifier): bool
    {
        return array_key_exists($identifier, $this->sessions);
    }
}

final class IntegrationSession implements Session
{
    private bool $started = false;
    private ?string $identifier = null;

    public function __construct(
        private readonly IntegrationSessionStore $store,
        private ?string $incomingIdentifier = null,
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->identifier = $this->store->open($this->incomingIdentifier);
        $this->incomingIdentifier = null;
        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function id(): string
    {
        $this->start();
        return $this->identifier ?? throw new RuntimeException('Test session did not start.');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($this->id(), $key, $default);
    }

    public function put(string $key, mixed $value): void
    {
        $this->store->put($this->id(), $key, $value);
    }

    public function remove(string $key): void
    {
        $this->store->remove($this->id(), $key);
    }

    public function regenerate(): void
    {
        $this->identifier = $this->store->regenerate($this->id(), true);
    }

    public function invalidate(): void
    {
        $this->identifier = $this->store->regenerate($this->id(), false);
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
        if ($this->identifier !== null) {
            $this->incomingIdentifier = $this->identifier;
        }

        $this->started = false;
    }

    public function currentIdentifier(): string
    {
        return $this->identifier ?? $this->id();
    }
}

final class IntegrationLogger implements Logger
{
    /** @var list<Throwable> */
    public array $exceptions = [];

    public function error(Throwable $exception): void
    {
        $this->exceptions[] = $exception;
    }
}

final class LoginPageAction
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function __invoke(): ResponseInterface
    {
        return Response::json(['csrf_token' => $this->csrf->token()]);
    }
}

final class LoginAction
{
    public function __construct(
        private readonly TestCredentialLookup $users,
        private readonly PasswordHasher $passwords,
        private readonly Guard $guard,
        private readonly string $dummyHash,
    ) {
    }

    public function __invoke(Request $request): ResponseInterface
    {
        $email = $request->form('email');
        $password = $request->form('password');

        if (!is_string($email) || !is_string($password)) {
            return $this->rejected();
        }

        $user = $this->users->findByEmail($email);
        $hash = $user?->passwordHash() ?? $this->dummyHash;
        $valid = $this->passwords->verify($password, $hash);

        if (!$valid || $user === null) {
            return $this->rejected();
        }

        $this->guard->login($user);

        return Response::json([
            'authenticated' => true,
            'user' => $user->authIdentifier(),
        ]);
    }

    private function rejected(): ResponseInterface
    {
        return Response::json([
            'error' => [
                'code' => 'invalid_credentials',
                'message' => 'The supplied credentials are invalid.',
            ],
        ], 401);
    }
}

final class LogoutAction
{
    public function __construct(private readonly Guard $guard)
    {
    }

    public function __invoke(): ResponseInterface
    {
        $this->guard->logout();

        return Response::json(['authenticated' => false]);
    }
}

final class AccountAction
{
    public function __construct(
        private readonly Guard $guard,
        private readonly Csrf $csrf,
    ) {
    }

    public function __invoke(): ResponseInterface
    {
        return Response::json([
            'user' => $this->guard->id(),
            'csrf_token' => $this->csrf->token(),
        ]);
    }
}

final class EditUserAction
{
    public function __invoke(string $user): ResponseInterface
    {
        return Response::json(['edited' => $user]);
    }
}

final class AuthenticationExchange
{
    public function __construct(
        public readonly ResponseInterface $response,
        public readonly string $sessionIdentifier,
    ) {
    }
}

final class AuthenticationApplicationFixture
{
    public readonly IntegrationSessionStore $sessions;
    public readonly IntegrationLogger $logger;
    private readonly string $dummyHash;

    public function __construct(
        public readonly InMemoryUserProvider $users,
        public readonly PasswordHasher $passwords,
    ) {
        $this->sessions = new IntegrationSessionStore();
        $this->logger = new IntegrationLogger();
        $this->dummyHash = $this->passwords->hash('integration unknown-user dummy password');
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $headers
     */
    public function request(
        string $method,
        string $path,
        ?string $sessionIdentifier = null,
        array $body = [],
        array $headers = ['Accept' => 'application/json'],
    ): AuthenticationExchange {
        $container = new Container();
        $session = new IntegrationSession($this->sessions, $sessionIdentifier);
        $guard = new SessionGuard($session, $this->users, '_auth_user');
        $csrf = new Csrf($session);

        $container->instance(Session::class, $session);
        $container->instance(UserProvider::class, $this->users);
        $container->instance(TestCredentialLookup::class, $this->users);
        $container->instance(Guard::class, $guard);
        $container->singleton(
            PasswordHasher::class,
            fn (): PasswordHasher => $this->passwords,
        );
        $container->instance(Csrf::class, $csrf);
        $container->bind(
            LoginAction::class,
            fn (): LoginAction => new LoginAction(
                $this->users,
                $this->passwords,
                $guard,
                $this->dummyHash,
            ),
        );

        $gate = new AuthorizationGate($guard, $container);
        $gate->define(
            'user.edit',
            static fn (TestUser $actor, string $target): AuthorizationResult =>
                $actor->canEdit($target)
                    ? AuthorizationResult::allow()
                    : AuthorizationResult::deny(
                        'Sensitive application authorization detail.',
                        'test_user_cannot_edit',
                    ),
        );
        $container->instance(Gate::class, $gate);

        $requireAuthentication = new RequireAuthentication(
            $guard,
            static fn (Request $request): ResponseInterface => Response::json([
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Authentication is required.',
                ],
            ], 401),
        );
        $requireGuest = new RequireGuest(
            $guard,
            static fn (Request $request): ResponseInterface => Response::redirect('/account', 303),
        );
        $authorizeEdit = new Authorize(
            $gate,
            'user.edit',
            static fn (Request $request, RouteParameters $parameters): array => [
                $parameters->require('user'),
            ],
        );

        $router = new Router($container);
        $router->get('/login', LoginPageAction::class)->middleware($requireGuest);
        $router->post('/login', LoginAction::class)->middleware($requireGuest);
        $router->get('/account', AccountAction::class)->middleware($requireAuthentication);
        $router->patch('/users/{user}', EditUserAction::class)
            ->middleware($requireAuthentication, $authorizeEdit);
        $router->post('/logout', LogoutAction::class)->middleware($requireAuthentication);

        $application = new Application(
            $router,
            exceptions: new ExceptionHandler(false, $this->logger),
        );
        $application->middleware(
            new SessionMiddleware($session),
            new VerifyCsrfToken($session),
        );

        $response = $application->handle(new Request(
            $method,
            $path,
            body: $body,
            headers: $headers,
        ));

        return new AuthenticationExchange($response, $session->currentIdentifier());
    }
}

