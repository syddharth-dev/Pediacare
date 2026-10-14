<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use PDO;

class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function adminExists(): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM users WHERE role = :role LIMIT 1');
        $statement->execute(['role' => 'ADMIN']);

        return $statement->fetchColumn() !== false;
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name, email, role, active FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    public function recordLogin(int $id, string $passwordHash, string $at): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, last_login_at = :at WHERE id = :id AND active = 1'
        );
        $statement->execute(['hash' => $passwordHash, 'at' => $at, 'id' => $id]);
    }

    public function insertAdmin(string $name, string $email, string $passwordHash): void
    {
        $this->insert($name, $email, $passwordHash, 'ADMIN');
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare('SELECT id, name, email, role, active FROM users ORDER BY name, id');
        $statement->execute();

        return $statement->fetchAll();
    }

    public function deactivate(int $id): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET active = 0 WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function changeRole(int $id, string $role): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
        $statement->execute(['role' => $role, 'id' => $id]);
    }

    public function insert(string $name, string $email, string $passwordHash, string $role): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, active) '
            . 'VALUES (:name, :email, :password_hash, :role, :active)'
        );
        $statement->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
            'active' => 1,
        ]);
    }
}
