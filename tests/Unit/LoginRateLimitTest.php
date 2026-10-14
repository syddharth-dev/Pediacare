<?php

declare(strict_types=1);

use Pediacare\Core\Session;
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\AuthService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LoginRateLimitTest extends TestCase
{
    #[DataProvider('limits')]
    public function test_T09_five_failures_for_email_or_ip_block_password_check(array $counts): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('findByEmail');
        $attempts = $this->createMock(LoginAttemptRepository::class);
        $attempts->expects(self::exactly(2))->method('lock');
        $attempts->expects(self::exactly(2))->method('unlock');
        $attempts->expects(self::once())->method('failures')->with(
            'fake@example.invalid', '2001:db8::1', '2026-01-01 11:45:00'
        )->willReturn($counts);
        $attempts->expects(self::never())->method('record');

        self::assertFalse((new AuthService($users, $attempts, new Session()))->login(
            'FAKE@example.invalid', 'FAKE_password', '2001:0db8:0:0:0:0:0:1', new DateTimeImmutable('2026-01-01 12:00:00')
        ));
    }

    public static function limits(): array
    {
        return [[['email' => 5, 'ip' => 0]], [['email' => 0, 'ip' => 5]]];
    }

    public function test_T09_partial_lock_failure_releases_acquired_lock(): void
    {
        $users = $this->createMock(UserRepository::class);
        $attempts = $this->createMock(LoginAttemptRepository::class);
        $calls = 0;
        $attempts->method('lock')->willReturnCallback(static function (string $name) use (&$calls): void {
            if (++$calls === 2) {
                throw new RuntimeException('FAKE_LOCK_FAILURE');
            }
        });
        $attempts->expects(self::once())->method('unlock');
        $this->expectException(RuntimeException::class);

        (new AuthService($users, $attempts, new Session()))->login('fake@example.invalid', 'FAKE_password', '192.0.2.1');
    }

    public function test_T09_malformed_credentials_do_not_reach_database(): void
    {
        $users = $this->createMock(UserRepository::class);
        $attempts = $this->createMock(LoginAttemptRepository::class);
        $attempts->expects(self::never())->method('lock');

        self::assertFalse((new AuthService($users, $attempts, new Session()))->login('invalid', 'FAKE', '192.0.2.1'));
    }
}
