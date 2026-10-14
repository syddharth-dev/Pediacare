<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    #[DataProvider('invalidConfigurationProvider')]
    public function test_T03_invalid_configuration_is_rejected_before_connecting(array $overrides): void
    {
        $config = array_replace([
            'host' => '127.0.0.1',
            'port' => 3306,
            'name' => 'pediacare_test',
            'username' => 'FAKE_DATABASE_USER',
            'password' => 'FAKE_DATABASE_PASSWORD',
            'charset' => 'utf8mb4',
        ], $overrides);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database configuration is invalid.');

        Database::connect($config);
    }

    public static function invalidConfigurationProvider(): array
    {
        return [
            'host cannot inject DSN fields' => [['host' => '127.0.0.1;dbname=other']],
            'database cannot inject DSN fields' => [['name' => 'pediacare_test;charset=latin1']],
            'port must be positive' => [['port' => 0]],
            'port must fit TCP range' => [['port' => 65536]],
            'port must be an integer' => [['port' => '3306']],
            'username must be a string' => [['username' => null]],
            'password must be a string' => [['password' => null]],
            'charset must be utf8mb4' => [['charset' => 'latin1']],
        ];
    }
}
