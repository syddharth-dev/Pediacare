<?php

declare(strict_types=1);

namespace Pediacare\Middleware;

use Closure;
use Pediacare\Core\Csrf;
use Pediacare\Core\Request;
use Pediacare\Core\Response;

final class CsrfMiddleware
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function handle(Request $request, Closure $next, array $roles = []): Response
    {
        if ($request->method === 'POST') {
            $token = $request->post['_csrf_token'] ?? null;

            if (!is_string($token) || !$this->csrf->verify($token)) {
                return new Response('Your session or form has expired. Reload the page and try again.', 403, [
                    'Content-Type' => 'text/plain; charset=UTF-8',
                ]);
            }
        }

        return $next($request);
    }
}
