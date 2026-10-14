<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use PDO;
use RuntimeException;

class LoginAttemptRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function lock(string $name): void
    {
        // Serialize checks and recording across sessions: https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/get_lock
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, 5)');
        $statement->execute(['name' => $name]);

        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('Login protection is temporarily unavailable.');
        }
    }

    public function unlock(string $name): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $statement->execute(['name' => $name]);
    }

    public function failures(string $email, string $ip, string $since): array
    {
        $statement = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM login_attempts WHERE success = 0 '
            . 'AND email = :email AND attempted_at > :email_since) AS email_count, '
            . '(SELECT COUNT(*) FROM login_attempts WHERE success = 0 '
            . 'AND ip_address = :ip AND attempted_at > :ip_since) AS ip_count'
        );
        $statement->execute(['email' => $email, 'email_since' => $since, 'ip' => $ip, 'ip_since' => $since]);
        $row = $statement->fetch();

        return ['email' => (int) $row['email_count'], 'ip' => (int) $row['ip_count']];
    }

    public function record(string $email, string $ip, string $at, bool $success): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (email, ip_address, attempted_at, success) '
            . 'VALUES (:email, :ip, :at, :success)'
        );
        $statement->execute(['email' => $email, 'ip' => $ip, 'at' => $at, 'success' => (int) $success]);
    }
}
