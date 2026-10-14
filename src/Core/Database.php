<?php

declare(strict_types=1);

namespace Pediacare\Core;

use InvalidArgumentException;
use PDO;

final class Database
{
    /** @param array{host: string, port: int, name: string, username: string, password: string, charset: string} $config */
    public static function connect(array $config): PDO
    {
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;
        $name = $config['name'] ?? null;

        if (
            !is_string($host) || preg_match('/\A[a-zA-Z0-9.:-]+\z/', $host) !== 1
            || !is_int($port) || $port < 1 || $port > 65535
            || !is_string($name) || preg_match('/\A[a-zA-Z0-9_]+\z/', $name) !== 1
            || !is_string($config['username'] ?? null)
            || !is_string($config['password'] ?? null)
            || ($config['charset'] ?? null) !== 'utf8mb4'
        ) {
            throw new InvalidArgumentException('Database configuration is invalid.');
        }

        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
}
