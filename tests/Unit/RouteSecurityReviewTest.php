<?php

declare(strict_types=1);

use Pediacare\Core\{Container, Csrf, Request, Response, Router, Session};
use Pediacare\Middleware\{AuthMiddleware, CsrfMiddleware, RoleMiddleware};
use Pediacare\Repositories\{LoginAttemptRepository, UserRepository};
use Pediacare\Services\AuthService;
use PHPUnit\Framework\TestCase;

final class RouteSecurityReviewTest extends TestCase
{
    public function test_AC01_AC02_AC03_all_registered_routes_enforce_the_spec_role_matrix(): void
    {
        $previous = $_SESSION ?? [];
        $currentRole = null;
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturnCallback(static function () use (&$currentRole): ?array {
            return $currentRole === null ? null : ['id' => 42, 'active' => 1, 'role' => $currentRole];
        });
        $auth = new AuthService($users, $this->createMock(LoginAttemptRepository::class), new Session());
        $routes = require dirname(__DIR__, 2) . '/src/routes.php';
        $container = $this->container($routes);
        $container->register(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($auth));
        $container->register(RoleMiddleware::class, static fn (): RoleMiddleware => new RoleMiddleware($auth));
        $router = new Router($container, $routes);

        try {
            foreach ($routes as $route) {
                $public = $route['path'] === '/login';
                self::assertSame($public ? [] : [AuthMiddleware::class, RoleMiddleware::class], $route['middleware'] ?? []);
                $allowed = $this->expectedRoles($route['path'], $route['method']);
                self::assertSame($allowed, $route['roles'] ?? []);
                foreach ([null, 'ADMIN', 'STAFF', 'BHW', 'VIEWER', 'FAKE_UNKNOWN_ROLE'] as $currentRole) {
                    $_SESSION = $currentRole === null ? [] : ['user_id' => 42];
                    $path = str_replace('{id}', '42', $route['path']);
                    $methods = $route['method'] === 'GET' ? ['GET', 'HEAD'] : ['POST'];
                    foreach ($methods as $method) {
                        $response = $router->dispatch(new Request($method, $path));
                        $status = $public ? 200 : ($currentRole === null ? 303 : (in_array($currentRole, $allowed, true) ? 200 : 403));
                        self::assertSame($status, $response->status, $method . ' ' . $path . ' role=' . ($currentRole ?? 'logged out'));
                        if ($status === 200) {
                            self::assertSame('FAKE handler reached', $response->body);
                        } elseif ($status === 303) {
                            self::assertSame('/login', $response->headers['Location']);
                        } else {
                            self::assertStringNotContainsString('FAKE handler reached', $response->body);
                        }
                    }
                }
            }
        } finally {
            $_SESSION = $previous;
        }
    }

    public function test_AC01_AC11_every_post_rejects_invalid_csrf_before_authentication_or_routing(): void
    {
        $previous = $_SESSION ?? [];
        $_SESSION = ['user_id' => 42];
        try {
            $csrf = new Csrf($_SESSION);
            $token = $csrf->token();
            $otherSession = [];
            $otherToken = (new Csrf($otherSession))->token();
            $users = $this->createMock(UserRepository::class);
            $users->expects(self::never())->method('findById');
            $auth = new AuthMiddleware(new AuthService($users, $this->createMock(LoginAttemptRepository::class), new Session()));
            $router = new Router(new Container(), require dirname(__DIR__, 2) . '/src/routes.php');
            $middleware = new CsrfMiddleware($csrf);
            foreach (require dirname(__DIR__, 2) . '/src/routes.php' as $route) {
                if ($route['method'] !== 'POST') {
                    continue;
                }
                $path = str_replace('{id}', '42', $route['path']);
                foreach ([[], ['_csrf_token' => ''], ['_csrf_token' => 'FAKE-invalid'],
                    ['_csrf_token' => [$token]], ['_csrf_token' => $otherToken]] as $post) {
                    $response = $middleware->handle(new Request('POST', $path, [], $post),
                        static fn (Request $request): Response => $auth->handle($request,
                            static fn (Request $request): Response => $router->dispatch($request)));
                    self::assertSame(403, $response->status, $path);
                    self::assertStringNotContainsString($token, $response->body);
                }
                $accepted = new Response('FAKE valid form');
                self::assertSame($accepted, $middleware->handle(new Request('POST', $path, [], ['_csrf_token' => $token]),
                    static fn (): Response => $accepted));
            }
        } finally {
            $_SESSION = $previous;
        }
    }

    private function expectedRoles(string $path, string $method): array
    {
        if ($path === '/login') {
            return [];
        }
        if (str_starts_with($path, '/admin/')) {
            return ['ADMIN'];
        }
        if ($path === '/logout') {
            return ['ADMIN', 'STAFF', 'BHW', 'VIEWER'];
        }
        if (in_array($path, ['/reports/nutrition', '/reports/immunization'], true)) {
            return ['ADMIN', 'STAFF', 'VIEWER'];
        }
        if (preg_match('~/children/\{id\}/(?:edit|update|archive|restore|immunizations(?:/new)?)\z~', $path) === 1) {
            return ['ADMIN', 'STAFF'];
        }
        self::assertTrue(str_starts_with($path, '/children')
            || in_array($path, ['/reports/malnutrition', '/immunizations/defaulters'], true),
            'Add a spec-based expectation for ' . $method . ' ' . $path);

        return ['ADMIN', 'STAFF', 'BHW'];
    }

    private function container(array $routes): Container
    {
        $container = new Container();
        foreach ($routes as $route) {
            $container->register($route['handler'][0], static fn (): object => new class {
                public function __call(string $method, array $arguments): Response
                {
                    return new Response('FAKE handler reached');
                }
            });
        }

        return $container;
    }
}
