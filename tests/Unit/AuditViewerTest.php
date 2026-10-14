<?php

declare(strict_types=1);

use Pediacare\Controllers\Admin\AuditController;
use Pediacare\Controllers\AuthController;
use Pediacare\Core\Container;
use Pediacare\Core\Csrf;
use Pediacare\Core\Request;
use Pediacare\Core\Router;
use Pediacare\Core\Session;
use Pediacare\Core\View;
use Pediacare\Middleware\AuthMiddleware;
use Pediacare\Middleware\RoleMiddleware;
use Pediacare\Repositories\AuditRepository;
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\AuditService;
use Pediacare\Services\AuthService;
use Pediacare\Services\UserService;
use Pediacare\Validation\AuditFilterValidator;
use Pediacare\Validation\UserValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditViewerTest extends TestCase
{
    private AuditRepository $audit;
    private UserRepository $users;
    private Router $router;
    private array $previousSession;

    protected function setUp(): void
    {
        $this->previousSession = $_SESSION ?? [];
        $_SESSION = ['user_id' => 42];
        $session = new Session();
        $this->users = $this->createMock(UserRepository::class);
        $this->audit = $this->createMock(AuditRepository::class);
        $auth = new AuthService($this->users, $this->createMock(LoginAttemptRepository::class), $session);
        $view = new View(dirname(__DIR__, 2) . '/views');
        $container = new Container();
        $container->register(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($auth));
        $container->register(RoleMiddleware::class, static fn (): RoleMiddleware => new RoleMiddleware($auth));
        $container->register(AuditController::class, fn (): AuditController => new AuditController(
            new AuditService($this->audit), new UserService($this->users, new UserValidator()),
            $auth, new AuditFilterValidator(), $view
        ));
        $data = [];
        $csrf = new Csrf($data);
        $container->register(AuthController::class, static fn (): AuthController => new AuthController($auth, $csrf, $view));
        $this->router = new Router($container, require dirname(__DIR__, 2) . '/src/routes.php');
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->previousSession;
    }

    public function test_AC11_admin_sees_actor_timestamp_changed_fields_and_retained_filters_escaped(): void
    {
        $this->users->method('findById')->willReturn(['id' => 42, 'name' => 'FAKE Admin', 'role' => 'ADMIN', 'active' => 1]);
        $this->users->method('all')->willReturn([['id' => 42, 'name' => '<FAKE Actor>']]);
        $filters = ['user_id' => '42', 'entity' => 'children', 'from' => '2026-10-01', 'to' => '2026-10-10'];
        $this->audit->expects(self::once())->method('search')->with($filters)->willReturn([
            ['action' => 'UPDATE', 'entity' => 'children', 'entity_id' => 7, 'user_id' => 42,
                'user_name' => '<FAKE Actor>', 'created_at' => '2026-10-10 23:59:59', 'ip_address' => null,
                'changes' => '{"before":{"notes":"FAKE old"},"after":{"notes":"<script>FAKE</script>"}}'],
        ]);
        $this->audit->method('entities')->willReturn(['children']);
        $response = $this->router->dispatch(new Request('GET', '/admin/audit', $filters));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('&lt;FAKE Actor&gt;', $response->body);
        self::assertStringContainsString('2026-10-10 23:59:59', $response->body);
        self::assertStringContainsString('FAKE old', $response->body);
        self::assertStringContainsString('&lt;script&gt;FAKE', $response->body);
        self::assertStringNotContainsString('<script>FAKE', $response->body);
        self::assertStringContainsString('value="42" selected', $response->body);
        self::assertStringContainsString('value="children" selected', $response->body);
        self::assertStringContainsString('value="2026-10-01"', $response->body);
        self::assertStringContainsString('/admin/audit', $this->router->dispatch(new Request('GET', '/login'))->body);
    }

    public function test_AC11_empty_log_has_guidance_and_invalid_filters_never_query_entries(): void
    {
        $this->users->method('findById')->willReturn(['id' => 42, 'role' => 'ADMIN', 'active' => 1]);
        $this->audit->expects(self::once())->method('search')->willReturn([]);
        self::assertStringContainsString('No audit entries match', $this->router->dispatch(new Request('GET', '/admin/audit'))->body);
        $response = $this->router->dispatch(new Request('GET', '/admin/audit', ['from' => '2026-02-30']));
        self::assertSame(422, $response->status);
        self::assertStringContainsString('Enter valid dates', $response->body);
    }

    public function test_AC11_non_admin_roles_are_denied_and_navigation_is_hidden(): void
    {
        $role = 'STAFF';
        $this->users->method('findById')->willReturnCallback(static function () use (&$role): array {
            return ['id' => 42, 'role' => $role, 'active' => 1];
        });
        $this->audit->expects(self::never())->method('search');
        foreach (['STAFF', 'BHW', 'VIEWER'] as $role) {
            self::assertSame(403, $this->router->dispatch(new Request('GET', '/admin/audit'))->status);
            self::assertStringNotContainsString('/admin/audit', $this->router->dispatch(new Request('GET', '/login'))->body);
        }
    }

    public function test_AC01_logged_out_audit_request_redirects_to_login(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->audit->expects(self::never())->method('search');
        $response = $this->router->dispatch(new Request('GET', '/admin/audit'));
        self::assertSame(303, $response->status);
        self::assertSame('/login', $response->headers['Location']);
    }

    #[DataProvider('invalidFilters')]
    public function test_AC11_invalid_filter_input_is_rejected(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AuditFilterValidator())->validate($input);
    }

    public static function invalidFilters(): array
    {
        return [
            [['user_id' => ['42']]], [['user_id' => '0']], [['user_id' => '4294967296']],
            [['entity' => "children' OR 1=1"]], [['entity' => str_repeat('a', 65)]],
            [['from' => '2026-02-29']], [['to' => 'not-a-date']], [['from' => '0999-01-01']],
            [['from' => '2026-10-11', 'to' => '2026-10-10']], [['to' => ['2026-10-10']]],
            [['from' => "2026-10-10\0"]],
        ];
    }
}
