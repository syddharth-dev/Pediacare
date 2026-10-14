<?php

declare(strict_types=1);

namespace Pediacare\Middleware;

use Closure;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Services\AuthService;

final class AuthMiddleware
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request, Closure $next, array $roles = []): Response
    {
        if ($request->path !== '/login' && $this->auth->currentUser() === null) {
            return Response::redirect('/login');
        }

        return $next($request);
    }
}
