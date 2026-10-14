<?php

declare(strict_types=1);

use Pediacare\Core\Csrf;
use Pediacare\Core\Container;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\Router;
use Pediacare\Core\Session;
use Pediacare\Core\View;
use Pediacare\Controllers\AuthController;
use Pediacare\Middleware\CsrfMiddleware;
use Pediacare\Middleware\AuthMiddleware;
use Pediacare\Middleware\RoleMiddleware;
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\AuthService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthServiceTest extends TestCase
{
    private Session $session;
    private UserRepository $users;
    private LoginAttemptRepository $attempts;
    private AuthService $auth;

    protected function setUp(): void
    {
        session_save_path(sys_get_temp_dir());
        $this->session = new Session();
        $this->session->start(false);
        $this->users = $this->createMock(UserRepository::class);
        $this->attempts = $this->createMock(LoginAttemptRepository::class);
        $this->attempts->method('failures')->willReturn(['email' => 0, 'ip' => 0]);
        $this->auth = new AuthService($this->users, $this->attempts, $this->session);
    }

    protected function tearDown(): void
    {
        session_destroy();
    }

    public function test_T09_success_renews_session_rehashes_password_and_records_login(): void
    {
        $oldId = session_id();
        $csrf = new Csrf($_SESSION);
        $oldToken = $csrf->token();
        $hash = password_hash('FAKE_password', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->users->expects(self::once())->method('findByEmail')->with('fake@example.invalid')->willReturn([
            'id' => 42, 'active' => 1, 'password_hash' => $hash,
        ]);
        $this->users->expects(self::once())->method('recordLogin')->willReturnCallback(
            static function (int $id, string $newHash, string $at) use ($hash): void {
                self::assertSame(42, $id);
                self::assertNotSame($hash, $newHash);
                self::assertTrue(password_verify('FAKE_password', $newHash));
                self::assertSame('2026-01-01 12:00:00', $at);
            }
        );
        $this->attempts->expects(self::exactly(2))->method('lock');
        $this->attempts->expects(self::exactly(2))->method('unlock');
        $this->attempts->expects(self::once())->method('record')->with(
            'fake@example.invalid', '192.0.2.1', '2026-01-01 12:00:00', true
        );

        self::assertTrue($this->auth->login(' FAKE@example.invalid ', 'FAKE_password', '192.0.2.1',
            new DateTimeImmutable('2026-01-01 12:00:00')));
        self::assertSame(42, $this->session->userId());
        self::assertNotSame($oldId, session_id());
        self::assertFalse($csrf->verify($oldToken));
        self::assertNotSame($oldToken, $csrf->token());
    }

    #[DataProvider('failedAccounts')]
    public function test_T09_failed_login_records_failure_without_session_authentication(?array $user): void
    {
        if ($user !== null) {
            $user['password_hash'] = password_hash('FAKE_correct', PASSWORD_BCRYPT, ['cost' => 4]);
        }

        $this->users->method('findByEmail')->willReturn($user);
        $this->users->expects(self::never())->method('recordLogin');
        $this->attempts->expects(self::once())->method('record')->with(
            'fake@example.invalid', '192.0.2.1', self::anything(), false
        );
        $id = session_id();

        self::assertFalse($this->auth->login('fake@example.invalid', 'FAKE_wrong', '192.0.2.1'));
        self::assertNull($this->session->userId());
        self::assertSame($id, session_id());
    }

    public static function failedAccounts(): array
    {
        return ['unknown' => [null], 'wrong password' => [['id' => 1, 'active' => 1]],
            'inactive' => [['id' => 1, 'active' => 0]]];
    }

    public function test_T09_inactive_account_is_rejected_even_with_correct_password(): void
    {
        $this->users->method('findByEmail')->willReturn([
            'id' => 1, 'active' => 0, 'password_hash' => password_hash('FAKE_password', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);

        self::assertFalse($this->auth->login('fake@example.invalid', 'FAKE_password', '192.0.2.1'));
        self::assertNull($this->session->userId());
    }

    public function test_T09_logout_clears_user_and_token_and_renews_id(): void
    {
        $this->session->renew(42);
        $id = session_id();
        $csrf = new Csrf($_SESSION);
        $token = $csrf->token();
        $this->auth->logout();

        self::assertNull($this->session->userId());
        self::assertFalse($csrf->verify($token));
        self::assertNotSame($id, session_id());
    }

    public function test_T09_deactivated_session_user_is_not_returned(): void
    {
        $this->session->renew(42);
        $this->users->method('findById')->willReturn(['id' => 42, 'active' => 0]);

        self::assertNull($this->auth->currentUser());
    }

    public function test_T09_routes_render_login_and_protect_login_and_logout_with_csrf(): void
    {
        $this->users->method('findByEmail')->willReturn([
            'id' => 42, 'active' => 1, 'password_hash' => password_hash('FAKE_password', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $this->users->method('findById')->willReturn(['id' => 42, 'active' => 1, 'name' => '<FAKE Staff>', 'role' => 'STAFF']);
        $csrf = new Csrf($_SESSION);
        $controller = new AuthController($this->auth, $csrf, new View(dirname(__DIR__, 2) . '/views'));
        $container = new Container();
        $container->register(AuthController::class, static fn (Container $container): AuthController => $controller);
        $container->register(AuthMiddleware::class, fn (Container $container): AuthMiddleware => new AuthMiddleware($this->auth));
        $container->register(RoleMiddleware::class, fn (Container $container): RoleMiddleware => new RoleMiddleware($this->auth));
        $router = new Router($container, require dirname(__DIR__, 2) . '/src/routes.php');
        $middleware = new CsrfMiddleware($csrf);
        $dispatch = static fn (Request $request): Response => $middleware->handle(
            $request, static fn (Request $request): Response => $router->dispatch($request)
        );
        $form = $dispatch(new Request('GET', '/login'));
        self::assertSame(200, $form->status);
        self::assertStringContainsString('autocomplete="current-password"', $form->body);
        self::assertStringContainsString('<label for="email">Email</label>', $form->body);
        self::assertStringContainsString('name="_csrf_token"', $form->body);
        self::assertSame(403, $dispatch(new Request('POST', '/login'))->status);
        $token = $csrf->token();
        $post = ['_csrf_token' => $token, 'email' => 'fake@example.invalid', 'password' => 'FAKE_password'];
        $success = $dispatch(new Request('POST', '/login', [], $post));
        self::assertSame(303, $success->status);
        self::assertSame('/login', $success->headers['Location']);
        $signedIn = $dispatch(new Request('GET', '/login'));
        self::assertStringContainsString('&lt;FAKE Staff&gt;', $signedIn->body);
        self::assertStringContainsString('action="/logout"', $signedIn->body);
        self::assertStringNotContainsString('FAKE_password', $signedIn->body);
        self::assertSame(405, $dispatch(new Request('GET', '/logout'))->status);
        self::assertSame(403, $dispatch(new Request('POST', '/logout', [], ['_csrf_token' => $token]))->status);
        self::assertSame(42, $this->session->userId());
        self::assertSame(303, $dispatch(new Request('POST', '/logout', [], ['_csrf_token' => $csrf->token()]))->status);
        self::assertNull($this->session->userId());
    }

    public function test_T09_array_credentials_return_generic_failure_without_echoing_input(): void
    {
        $this->users->expects(self::never())->method('findByEmail');
        $controller = new AuthController($this->auth, new Csrf($_SESSION), new View(dirname(__DIR__, 2) . '/views'));
        $response = $controller->login(new Request('POST', '/login', [], [
            'email' => ['fake@example.invalid'], 'password' => 'FAKE_SECRET_DO_NOT_DISPLAY',
        ]));

        self::assertSame(401, $response->status);
        self::assertStringContainsString('Unable to sign in.', $response->body);
        self::assertStringNotContainsString('FAKE_SECRET_DO_NOT_DISPLAY', $response->body);
    }
}
