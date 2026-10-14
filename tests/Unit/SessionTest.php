<?php

declare(strict_types=1);

use Pediacare\Core\Session;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        session_save_path(sys_get_temp_dir());
        $_COOKIE = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function test_T07_https_session_uses_secure_cookie_settings(): void
    {
        (new Session())->start(true, 1000);

        $params = session_get_cookie_params();
        self::assertTrue($params['secure']);
        self::assertTrue($params['httponly']);
        self::assertSame('Lax', $params['samesite']);
        self::assertSame(0, $params['lifetime']);
        self::assertSame('/', $params['path']);
        self::assertSame('1', ini_get('session.use_strict_mode'));
        self::assertSame('1', ini_get('session.use_cookies'));
        self::assertSame('1', ini_get('session.use_only_cookies'));
        self::assertSame('0', ini_get('session.use_trans_sid'));
        self::assertSame('1800', ini_get('session.gc_maxlifetime'));
    }

    public function test_T07_local_http_session_does_not_require_https_cookie(): void
    {
        (new Session())->start(false, 1000);

        self::assertFalse(session_get_cookie_params()['secure']);
        self::assertTrue(session_get_cookie_params()['httponly']);
    }

    public function test_T07_idle_timeout_clears_data_and_changes_id_at_30_minutes(): void
    {
        $session = new Session();
        $session->start(false, 1000);
        $originalId = session_id();
        $_SESSION['user_id'] = 123;
        $_SESSION['_csrf_token'] = 'fake-token';
        $_SESSION['_flash'] = ['fake-message'];
        session_write_close();

        $session->start(false, 2799);
        self::assertSame(123, $_SESSION['user_id']);
        self::assertSame($originalId, session_id());
        self::assertSame(2799, $_SESSION['_last_activity']);
        session_write_close();

        $session->start(false, 4599);
        self::assertSame(['_last_activity' => 4599], $_SESSION);
        self::assertNotSame($originalId, session_id());
    }

    public function test_T07_existing_session_without_valid_timestamp_is_cleared(): void
    {
        $session = new Session();
        $session->start(false, 1000);
        $_SESSION['user_id'] = 123;
        $_SESSION['_last_activity'] = 'invalid';
        session_write_close();

        $session->start(false, 1001);

        self::assertSame(['_last_activity' => 1001], $_SESSION);
    }

    public function test_T07_strict_mode_rejects_uninitialized_session_id(): void
    {
        $uninitializedId = bin2hex(random_bytes(16));
        session_id($uninitializedId);

        (new Session())->start(false, 1000);

        self::assertNotSame($uninitializedId, session_id());
    }

    public function test_T07_already_started_session_cannot_bypass_startup_settings(): void
    {
        $session = new Session();
        $session->start(false, 1000);
        $this->expectException(LogicException::class);

        $session->start(true, 1001);
    }
}
