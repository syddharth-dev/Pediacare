<?php

declare(strict_types=1);

use Pediacare\Controllers\Admin\PurokController;
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
use Pediacare\Repositories\PurokRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\AuthService;
use Pediacare\Services\PurokService;
use Pediacare\Validation\PurokValidator;
use PHPUnit\Framework\TestCase;

final class PurokManagementTest extends TestCase
{
    private UserRepository $users;
    private PurokRepository $puroks;
    private Csrf $csrf;
    private Router $router;
    private array $previousSession;

    protected function setUp(): void
    {
        $this->previousSession = $_SESSION ?? [];
        $_SESSION = ['user_id' => 42];
        $this->users = $this->createMock(UserRepository::class);
        $this->puroks = $this->createMock(PurokRepository::class);
        $auth = new AuthService($this->users, $this->createMock(LoginAttemptRepository::class), new Session());
        $this->csrf = new Csrf($_SESSION);
        $flash = new Flash($_SESSION);
        $view = new View(dirname(__DIR__, 2) . '/views');
        $container = new Container();
        $container->register(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($auth));
        $container->register(RoleMiddleware::class, static fn (): RoleMiddleware => new RoleMiddleware($auth));
        $container->register(AuthController::class, fn (): AuthController => new AuthController($auth, $this->csrf, $view));
        $container->register(PurokController::class, fn (): PurokController => new PurokController(
            new PurokService($this->puroks, new PurokValidator()), $auth, $this->csrf, $flash, $view
        ));
        $this->router = new Router($container, require dirname(__DIR__, 2) . '/src/routes.php');
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->previousSession;
    }

    private function dispatch(string $method, string $path, array $post = []): Response
    {
        return (new CsrfMiddleware($this->csrf))->handle(new Request($method, $path, [], $post),
            fn (Request $request): Response => $this->router->dispatch($request));
    }

    private function allowAdmin(): void
    {
        $this->users->method('findById')->willReturn(['id' => 42, 'name' => 'FAKE Admin', 'role' => 'ADMIN', 'active' => 1]);
        $this->puroks->method('all')->willReturn([['id' => 7, 'name' => '<FAKE & Sitio>']]);
        $this->puroks->method('findById')->willReturn(['id' => 7]);
    }

    public function test_AC01_logged_out_purok_page_redirects_to_login(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->puroks->expects(self::never())->method('all');
        $response = $this->dispatch('GET', '/admin/puroks');
        self::assertSame(303, $response->status);
        self::assertSame('/login', $response->headers['Location']);
    }

    public function test_AC02_non_admin_roles_cannot_see_ui_or_use_any_purok_route(): void
    {
        $role = 'BHW';
        $this->users->method('findById')->willReturnCallback(static function () use (&$role): array {
            return ['id' => 42, 'name' => 'FAKE Staff', 'role' => $role, 'active' => 1];
        });
        foreach (['all', 'insert', 'rename'] as $method) {
            $this->puroks->expects(self::never())->method($method);
        }
        foreach (['BHW', 'STAFF', 'VIEWER'] as $role) {
            self::assertStringNotContainsString('/admin/puroks', $this->dispatch('GET', '/login')->body);
            self::assertSame(403, $this->dispatch('GET', '/admin/puroks')->status);
            foreach (['/admin/puroks', '/admin/puroks/7/rename'] as $path) {
                self::assertSame(403, $this->dispatch('POST', $path, ['_csrf_token' => $this->csrf->token()])->status);
            }
        }
    }

    public function test_T15_admin_forms_have_labels_csrf_and_escaped_names(): void
    {
        $this->allowAdmin();
        $response = $this->dispatch('GET', '/admin/puroks');
        self::assertSame(200, $response->status);
        self::assertStringContainsString('&lt;FAKE &amp; Sitio&gt;', $response->body);
        self::assertStringContainsString('/admin/puroks', $this->dispatch('GET', '/login')->body);
        $document = new DOMDocument();
        @$document->loadHTML($response->body);
        $xpath = new DOMXPath($document);
        self::assertSame(2, $xpath->query('//form')->length);
        self::assertSame(2, $xpath->query('//form/input[@name="_csrf_token"]')->length);
        self::assertSame(2, $xpath->query('//label[@for="name" or @for="name-7"]')->length);
    }

    public function test_T15_writes_require_csrf_and_redirect_with_success_message(): void
    {
        $this->allowAdmin();
        $this->puroks->expects(self::once())->method('insert')->with('FAKE New');
        $this->puroks->expects(self::once())->method('rename')->with(7, 'FAKE Renamed');
        foreach (['/admin/puroks', '/admin/puroks/7/rename'] as $path) {
            self::assertSame(403, $this->dispatch('POST', $path, ['name' => 'FAKE'])->status);
        }
        $created = $this->dispatch('POST', '/admin/puroks', ['name' => ' FAKE New ', '_csrf_token' => $this->csrf->token()]);
        self::assertSame(303, $created->status);
        self::assertSame('/admin/puroks', $created->headers['Location']);
        self::assertStringContainsString('Purok or sitio added.', $this->dispatch('GET', '/admin/puroks')->body);
        self::assertSame(303, $this->dispatch('POST', '/admin/puroks/7/rename',
            ['name' => 'FAKE Renamed', '_csrf_token' => $this->csrf->token()])->status);
        self::assertStringContainsString('Purok or sitio renamed.', $this->dispatch('GET', '/admin/puroks')->body);
    }

    public function test_T15_duplicate_name_preserves_escaped_input_in_the_matching_form(): void
    {
        $this->allowAdmin();
        $error = new PDOException('FAKE private SQL');
        $error->errorInfo = ['23000', 1062, 'FAKE private SQL'];
        $this->puroks->method('insert')->willThrowException($error);
        $this->puroks->method('rename')->willThrowException($error);
        foreach (['/admin/puroks', '/admin/puroks/7/rename'] as $path) {
            $response = $this->dispatch('POST', $path, ['name' => '<FAKE Duplicate>', '_csrf_token' => $this->csrf->token()]);
            self::assertSame(422, $response->status);
            self::assertStringContainsString('value="&lt;FAKE Duplicate&gt;"', $response->body);
            self::assertStringContainsString('already uses this name', $response->body);
            self::assertStringNotContainsString('FAKE private SQL', $response->body);
        }
    }

    public function test_T15_empty_list_provides_registration_setup_guidance(): void
    {
        $this->users->method('findById')->willReturn(['id' => 42, 'role' => 'ADMIN', 'active' => 1]);
        self::assertStringContainsString('No puroks or sitios yet.', $this->dispatch('GET', '/admin/puroks')->body);
    }
}
