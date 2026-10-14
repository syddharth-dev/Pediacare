<?php

declare(strict_types=1);

use Pediacare\Core\Container;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\Router;
use Pediacare\Core\Session;
use Pediacare\Middleware\AuthMiddleware;
use Pediacare\Middleware\RoleMiddleware;
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\AuthService;
use Pediacare\Support\Permissions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthorizationTest extends TestCase
{
    private Session $session;
    private UserRepository $users;
    private AuthMiddleware $auth;
    private RoleMiddleware $roles;

    protected function setUp(): void
    {
        session_save_path(sys_get_temp_dir());
        $this->session = new Session();
        $this->session->start(false, 1000);
        $this->users = $this->createMock(UserRepository::class);
        $service = new AuthService($this->users, $this->createMock(LoginAttemptRepository::class), $this->session);
        $this->auth = new AuthMiddleware($service);
        $this->roles = new RoleMiddleware($service);
    }

    protected function tearDown(): void
    {
        session_destroy();
    }

    public function test_AC01_logged_out_visitors_redirect_before_routing_any_app_path(): void
    {
        $this->users->expects(self::never())->method('findById');

        foreach (['/', '/children', '/children/42', '/reports', '/FAKE-unknown', '/login/'] as $path) {
            $response = $this->auth->handle(new Request('GET', $path), static function (): Response {
                self::fail('Logged-out request reached routing.');
            });
            self::assertSame(303, $response->status);
            self::assertSame('/login', $response->headers['Location']);
        }

        $next = new Response('FAKE login');
        self::assertSame($next, $this->auth->handle(new Request('GET', '/login'), static fn (): Response => $next));
    }

    public function test_AC01_inactive_or_missing_accounts_cannot_use_existing_session(): void
    {
        $this->session->renew(42);
        $this->users->method('findById')->willReturnOnConsecutiveCalls(['id' => 42, 'active' => 0], null);

        foreach ([1, 2] as $attempt) {
            $response = $this->auth->handle(new Request('GET', '/children'), static function (): Response {
                self::fail('Inactive or missing account reached controller.');
            });
            self::assertSame('/login', $response->headers['Location']);
        }
    }

    public function test_AC01_idle_expiry_removes_access(): void
    {
        $_SESSION['user_id'] = 42;
        session_write_close();
        $this->session->start(false, 2800);
        $response = $this->auth->handle(new Request('GET', '/children'), static function (): Response {
            self::fail('Expired session reached controller.');
        });

        self::assertSame('/login', $response->headers['Location']);
    }

    public function test_AC02_bhw_route_rejection_prevents_controller_resolution(): void
    {
        $this->session->renew(42);
        $this->users->method('findById')->willReturn(['id' => 42, 'active' => 1, 'role' => 'BHW']);
        $container = new Container();
        $container->register(AuthMiddleware::class, fn (Container $container): AuthMiddleware => $this->auth);
        $container->register(RoleMiddleware::class, fn (Container $container): RoleMiddleware => $this->roles);

        foreach (['children.archive', 'users.manage', 'schedule.manage'] as $permission) {
            $router = new Router($container, [[
                'method' => 'GET', 'path' => '/FAKE-protected', 'handler' => ['FAKE_UNREGISTERED_CONTROLLER', 'index'],
                'middleware' => [AuthMiddleware::class, RoleMiddleware::class], 'roles' => Permissions::roles($permission),
            ]]);
            self::assertSame(403, $router->dispatch(new Request('GET', '/FAKE-protected'))->status);
        }
    }

    public function test_AC03_viewer_can_read_aggregates_but_not_individual_records(): void
    {
        $this->session->renew(42);
        $this->users->method('findById')->willReturn(['id' => 42, 'active' => 1, 'role' => 'VIEWER']);
        $next = new Response('FAKE aggregates');

        self::assertSame($next, $this->roles->handle(new Request('GET', '/FAKE-report'),
            static fn (): Response => $next, Permissions::roles('reports.aggregate')));
        self::assertSame(403, $this->roles->handle(new Request('GET', '/children/42'), static function (): Response {
            self::fail('Viewer reached child handler.');
        }, Permissions::roles('children.read'))->status);
    }

    public function test_T10_role_changes_take_effect_on_next_request_and_empty_roles_deny_access(): void
    {
        $this->session->renew(42);
        $this->users->method('findById')->willReturnOnConsecutiveCalls(
            ['id' => 42, 'active' => 1, 'role' => 'ADMIN'], ['id' => 42, 'active' => 1, 'role' => 'VIEWER'],
            ['id' => 42, 'active' => 1, 'role' => 'ADMIN'], ['id' => 42, 'active' => 1, 'role' => 'FAKE']
        );
        $next = new Response('FAKE permitted');
        $request = new Request('GET', '/FAKE-admin');
        self::assertSame($next, $this->roles->handle($request, static fn (): Response => $next, ['ADMIN']));
        self::assertSame(403, $this->roles->handle($request, static fn (): Response => $next, ['ADMIN'])->status);
        self::assertSame(403, $this->roles->handle($request, static fn (): Response => $next)->status);
        self::assertSame(403, $this->roles->handle($request, static fn (): Response => $next, ['FAKE'])->status);
    }
}
