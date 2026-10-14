<?php

declare(strict_types=1);

use Pediacare\Repositories\UserRepository;
use Pediacare\Services\AdminSeedService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminSeedServiceTest extends TestCase
{
    private const CONFIG = [
        'name' => 'FAKE Admin',
        'email' => 'fake-admin@example.invalid',
        'password' => 'FAKE_TEST_ONLY_password_123',
    ];

    public function test_T08_first_admin_password_is_hashed_before_persistence(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('adminExists')->willReturn(false);
        $users->expects(self::once())->method('insertAdmin')->willReturnCallback(
            static function (string $name, string $email, string $hash): void {
                self::assertSame(self::CONFIG['name'], $name);
                self::assertSame(self::CONFIG['email'], $email);
                self::assertNotSame(self::CONFIG['password'], $hash);
                self::assertTrue(password_verify(self::CONFIG['password'], $hash));
                self::assertSame(
                    defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt',
                    password_get_info($hash)['algoName']
                );
            }
        );

        self::assertTrue((new AdminSeedService($users))->run(self::CONFIG));
    }

    public function test_T08_existing_admin_is_never_changed_even_without_seed_config(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('adminExists')->willReturn(true);
        $users->expects(self::never())->method('insertAdmin');

        self::assertFalse((new AdminSeedService($users))->run([]));
    }

    #[DataProvider('invalidConfig')]
    public function test_T08_invalid_config_never_creates_account(array $config): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('adminExists')->willReturn(false);
        $users->expects(self::never())->method('insertAdmin');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('First Admin configuration is invalid.');

        (new AdminSeedService($users))->run($config);
    }

    public static function invalidConfig(): array
    {
        return [
            'missing' => [[]],
            'empty name' => [array_replace(self::CONFIG, ['name' => ' '])],
            'long name' => [array_replace(self::CONFIG, ['name' => str_repeat('x', 256)])],
            'invalid email' => [array_replace(self::CONFIG, ['email' => 'not-an-email'])],
            'empty password' => [array_replace(self::CONFIG, ['password' => ''])],
            'array password' => [array_replace(self::CONFIG, ['password' => ['fake']])],
            'null byte password' => [array_replace(self::CONFIG, ['password' => "fake\0password"])],
        ];
    }
}
