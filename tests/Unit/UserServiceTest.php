<?php

declare(strict_types=1);

use Pediacare\Repositories\UserRepository;
use Pediacare\Services\UserService;
use Pediacare\Validation\UserValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
    public function test_AC02_create_hashes_password_and_accepts_only_valid_role(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('insert')->willReturnCallback(
            static function (string $name, string $email, string $hash, string $role): void {
                self::assertSame('FAKE Staff', $name);
                self::assertSame('fake@example.invalid', $email);
                self::assertSame('BHW', $role);
                self::assertTrue(password_verify('FAKE_password', $hash));
                self::assertNotSame('FAKE_password', $hash);
            }
        );
        (new UserService($users, new UserValidator()))->create([
            'name' => ' FAKE Staff ', 'email' => ' FAKE@example.invalid ', 'password' => 'FAKE_password', 'role' => 'BHW',
        ]);
    }

    #[DataProvider('invalidInputs')]
    public function test_AC02_invalid_account_input_never_reaches_insert(array $input): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('insert');
        $this->expectException(InvalidArgumentException::class);
        (new UserService($users, new UserValidator()))->create($input);
    }

    public static function invalidInputs(): array
    {
        $valid = ['name' => 'FAKE', 'email' => 'fake@example.invalid', 'password' => 'FAKE_password', 'role' => 'STAFF'];

        return [[[]], [array_replace($valid, ['name' => []])], [array_replace($valid, ['name' => ' '])],
            [array_replace($valid, ['name' => str_repeat('x', 256)])], [array_replace($valid, ['email' => 'invalid'])],
            [array_replace($valid, ['password' => []])], [array_replace($valid, ['password' => ''])],
            [array_replace($valid, ['password' => "FAKE\0password"])], [array_replace($valid, ['role' => 'ROOT'])],
            [array_replace($valid, ['role' => ['ADMIN']])]];
    }

    public function test_AC02_duplicate_email_becomes_a_plain_validation_error(): void
    {
        $error = new PDOException('FAKE_SQL_SECRET');
        $error->errorInfo = ['23000', 1062, 'FAKE_SQL_SECRET'];
        $users = $this->createMock(UserRepository::class);
        $users->method('insert')->willThrowException($error);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An account already uses this email address.');
        (new UserService($users, new UserValidator()))->create([
            'name' => 'FAKE', 'email' => 'fake@example.invalid', 'password' => 'FAKE_password', 'role' => 'STAFF',
        ]);
    }

    public function test_AC02_role_change_and_deactivation_use_validated_existing_id(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->with(42)->willReturn(['id' => 42]);
        $users->expects(self::once())->method('changeRole')->with(42, 'VIEWER');
        $users->expects(self::once())->method('deactivate')->with(42);
        $service = new UserService($users, new UserValidator());
        $service->changeRole('42', ['role' => 'VIEWER']);
        $service->deactivate('42');
    }

    public function test_AC02_missing_account_is_not_updated(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(null);
        $users->expects(self::never())->method('deactivate');
        $this->expectException(InvalidArgumentException::class);
        (new UserService($users, new UserValidator()))->deactivate('42');
    }

    #[DataProvider('invalidIds')]
    public function test_AC02_invalid_id_is_rejected_before_database_access(string $id): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('findById');
        $this->expectException(InvalidArgumentException::class);
        (new UserService($users, new UserValidator()))->deactivate($id);
    }

    public static function invalidIds(): array
    {
        return [['0'], ['-1'], ['invalid'], ['4294967296'], ['1 OR 1=1']];
    }
}
