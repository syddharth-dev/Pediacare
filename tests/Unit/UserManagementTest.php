<?php

declare(strict_types=1);

use Pediacare\Controllers\Admin\UserController;
use Pediacare\Controllers\AuthController;
use Pediacare\Core\Container;
use Pediacare\Core\Csrf;
use Pediacare\Core\Flash;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\Router;
use Pediacare\Core\Session;
use Pediacare\Core\View;
use Pediacare\Middleware\AuthMiddleware;
use Pediacare\Middleware\CsrfMiddleware;
use Pediacare\Middleware\RoleMiddleware;
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\AuthService;
use Pediacare\Services\UserService;
use Pediacare\Validation\UserValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserManagementTest extends TestCase
{
    private UserRepository $users;
    private Csrf $csrf;
    private Router $router;

    protected function setUp(): void
    {
        session_save_path(sys_get_temp_dir());
        $session = new Session();
        $session->start(false);
        $session->renew(42);
        $this->users = $this->createMock(UserRepository::class);
        $auth = new AuthService($this->users, $this->createMock(LoginAttemptRepository::class), $session);
        $this->csrf = new Csrf($_SESSION);
        $flash = new Flash($_SESSION);
        $view = new View(dirname(__DIR__, 2) . '/views');
        $container = new Container();
        $container->register(AuthMiddleware::class, static fn (Container $container): AuthMiddleware => new AuthMiddleware($auth));
        $container->register(RoleMiddleware::class, static fn (Container $container): RoleMiddleware => new RoleMiddleware($auth));
        $container->register(AuthController::class, fn (Container $container): AuthController => new AuthController($auth, $this->csrf, $view));
        $container->register(UserController::class, fn (Container $container): UserController => new UserController(
            new UserService($this->users, new UserValidator()), $auth, $this->csrf, $flash, $view
        ));
        $this->router = new Router($container, require dirname(__DIR__, 2) . '/src/routes.php');
    }

    protected function tearDown(): void
    {
        session_destroy();
    }

    private function dispatch(string $method, string $path, array $post = []): Response
    {
        return (new CsrfMiddleware($this->csrf))->handle(new Request($method, $path, [], $post),
            fn (Request $request): Response => $this->router->dispatch($request));
    }

    #[DataProvider('restrictedRoles')]
    public function test_AC02_non_admin_has_no_management_ui_and_all_user_routes_return_403(string $role): void
    {
        $this->users->method('findById')->willReturn(['id' => 42, 'active' => 1, 'role' => $role, 'name' => 'FAKE Staff']);
        $this->users->expects(self::never())->method('all');
        $this->users->expects(self::never())->method('insert');
        $this->users->expects(self::never())->method('changeRole');
        $this->users->expects(self::never())->method('deactivate');
        $login = $this->dispatch('GET', '/login');
        self::assertStringNotContainsString('Manage users', $login->body);
        self::assertStringNotContainsString('/admin/users', $login->body);
        self::assertSame(403, $this->dispatch('GET', '/admin/users')->status);

        foreach (['/admin/users', '/admin/users/43/role', '/admin/users/43/deactivate'] as $path) {
            self::assertSame(403, $this->dispatch('POST', $path, ['_csrf_token' => $this->csrf->token()])->status);
        }
    }

    public static function restrictedRoles(): array
    {
        return [['BHW'], ['STAFF'], ['VIEWER']];
    }

    private function allowAdmin(): void
    {
        $this->users->method('findById')->willReturn(['id' => 42, 'active' => 1, 'role' => 'ADMIN', 'name' => 'FAKE Admin']);
        $this->users->method('all')->willReturn([
            ['id' => 43, 'active' => 1, 'role' => 'STAFF', 'name' => '<FAKE & Staff>', 'email' => 'fake@example.invalid'],
        ]);
    }

    public function test_AC02_admin_forms_are_escaped_and_every_form_has_csrf_token(): void
    {
        $this->allowAdmin();
        $response = $this->dispatch('GET', '/admin/users');
        self::assertSame(200, $response->status);
        self::assertStringContainsString('&lt;FAKE &amp; Staff&gt;', $response->body);
        self::assertStringContainsString('Confirm deactivation', $response->body);
        self::assertStringContainsString('Health Staff', $response->body);
        $document = new DOMDocument();
        @$document->loadHTML($response->body);
        $xpath = new DOMXPath($document);
        self::assertSame(3, $xpath->query('//form')->length);
        self::assertSame(3, $xpath->query('//form/input[@name="_csrf_token"]')->length);
        self::assertStringContainsString('/admin/users', $this->dispatch('GET', '/login')->body);
    }

    public function test_AC02_create_uses_post_redirect_get_and_never_redisplays_password(): void
    {
        $this->allowAdmin();
        $this->users->expects(self::once())->method('insert');
        self::assertSame(403, $this->dispatch('POST', '/admin/users')->status);
        $response = $this->dispatch('POST', '/admin/users', [
            '_csrf_token' => $this->csrf->token(), 'name' => 'FAKE New', 'email' => 'fake-new@example.invalid',
            'password' => 'FAKE_SECRET_password', 'role' => 'BHW',
        ]);
        self::assertSame(303, $response->status);
        self::assertSame('/admin/users', $response->headers['Location']);
        self::assertStringContainsString('Account created.', $this->dispatch('GET', '/admin/users')->body);
        $invalid = $this->dispatch('POST', '/admin/users', [
            '_csrf_token' => $this->csrf->token(), 'name' => '<FAKE Name>', 'email' => 'invalid', 'password' => 'FAKE_SECRET_password',
        ]);
        self::assertSame(422, $invalid->status);
        self::assertStringContainsString('&lt;FAKE Name&gt;', $invalid->body);
        self::assertStringNotContainsString('FAKE_SECRET_password', $invalid->body);
    }

    public function test_AC02_role_and_deactivation_posts_call_service_and_redirect(): void
    {
        $this->allowAdmin();
        $this->users->expects(self::once())->method('changeRole')->with(43, 'VIEWER');
        $this->users->expects(self::once())->method('deactivate')->with(43);
        self::assertSame(303, $this->dispatch('POST', '/admin/users/43/role', [
            '_csrf_token' => $this->csrf->token(), 'role' => 'VIEWER',
        ])->status);
        self::assertSame(303, $this->dispatch('POST', '/admin/users/43/deactivate', [
            '_csrf_token' => $this->csrf->token(),
        ])->status);
    }
}
