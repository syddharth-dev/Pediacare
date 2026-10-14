<?php

declare(strict_types=1);

namespace Pediacare\Middleware;

use Closure;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Services\AuthService;
use Pediacare\Support\Permissions;

final class RoleMiddleware
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request, Closure $next, array $roles = []): Response
    {
        $user = $this->auth->currentUser();
        $role = $user['role'] ?? null;

        if (!is_string($role) || !Permissions::allows($role, 'session.logout') || !in_array($role, $roles, true)) {
            return new Response('You do not have permission to access this page.', 403, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        return $next($request);
    }
}
