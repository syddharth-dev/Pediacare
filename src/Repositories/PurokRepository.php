<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use PDO;

class PurokRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM puroks ORDER BY name, id');
        $statement->execute();

        return $statement->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM puroks WHERE id = :id');
        $statement->execute(['id' => $id]);
        $purok = $statement->fetch();

        return $purok === false ? null : $purok;
    }

    public function insert(string $name): void
    {
        $statement = $this->pdo->prepare('INSERT INTO puroks (name) VALUES (:name)');
        $statement->execute(['name' => $name]);
    }

    public function rename(int $id, string $name): void
    {
        $statement = $this->pdo->prepare('UPDATE puroks SET name = :name WHERE id = :id');
        $statement->execute(['name' => $name, 'id' => $id]);
    }
}
