<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use LogicException;
use PDO;

class VisitRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(array $data): int
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Visit creation requires a transaction.');
        }
        $statement = $this->pdo->prepare('INSERT INTO visits (child_id, visit_date, reason, findings,
            actions, referred, referred_to, attended_by, notes) VALUES (:child_id, :visit_date, :reason,
            :findings, :actions, :referred, :referred_to, :attended_by, :notes)');
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function forChild(int $childId): array
    {
        $statement = $this->pdo->prepare('SELECT v.*, u.name AS attended_by_name FROM visits v
            JOIN users u ON u.id = v.attended_by WHERE v.child_id = :child_id ORDER BY v.visit_date DESC, v.id DESC');
        $statement->execute(['child_id' => $childId]);

        return $statement->fetchAll();
    }
}
