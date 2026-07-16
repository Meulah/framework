<?php

declare(strict_types=1);

namespace Meulah\Security\Csrf;

use Meulah\Http\Middleware;
use Meulah\Http\Request;
use Meulah\Http\RequestHandler;
use Meulah\Http\ResponseInterface;
use Meulah\Session\Session;

final class VerifyCsrfToken implements Middleware
{
    private readonly Csrf $csrf;

    /** @var list<string> */
    private readonly array $except;

    /** @param list<string> $except */
    public function __construct(Session $session, array $except = [])
    {
        $this->csrf = new Csrf($session);
        $this->except = array_values(array_unique(array_map($this->normalizeExclusion(...), $except)));
    }

    public function process(Request $request, RequestHandler $next): ResponseInterface
    {
        if ($this->isReading($request) || in_array($request->path(), $this->except, true)) {
            return $next->handle($request);
        }

        $formToken = $request->form(Csrf::FIELD);
        $headerToken = $request->header(Csrf::HEADER);

        if (
            $formToken !== null
            && $headerToken !== null
            && (!is_string($formToken)
                || !is_string($headerToken)
                || !hash_equals($formToken, $headerToken))
        ) {
            throw new CsrfTokenMismatch();
        }

        $token = $headerToken ?? $formToken;

        if (!$this->csrf->isValid($token)) {
            throw new CsrfTokenMismatch();
        }

        return $next->handle($request);
    }

    private function isReading(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function normalizeExclusion(mixed $path): string
    {
        if (
            !is_string($path)
            || $path === ''
            || !str_starts_with($path, '/')
        ) {
            throw new CsrfConfigurationException(
                'CSRF exclusions must be explicit route paths without patterns or query strings.',
            );
        }

        $path = rawurldecode($path);

        if (
            preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || str_contains($path, '*')
            || str_contains($path, '{')
            || str_contains($path, '}')
            || str_contains($path, '?')
            || str_contains($path, '#')
        ) {
            throw new CsrfConfigurationException(
                'CSRF exclusions must be explicit route paths without patterns or query strings.',
            );
        }

        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
