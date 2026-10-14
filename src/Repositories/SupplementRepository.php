<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use LogicException;
use PDO;

class SupplementRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(array $data): int
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Supplement creation requires a transaction.');
        }
        $statement = $this->pdo->prepare('INSERT INTO supplements (visit_id, type, dose, date_given)
            VALUES (:visit_id, :type, :dose, :date_given)');
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function forChild(int $childId): array
    {
        $statement = $this->pdo->prepare('SELECT s.* FROM supplements s JOIN visits v ON v.id = s.visit_id
            WHERE v.child_id = :child_id ORDER BY s.date_given, s.id');
        $statement->execute(['child_id' => $childId]);

        return $statement->fetchAll();
    }
}
