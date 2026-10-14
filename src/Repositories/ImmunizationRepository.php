<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use LogicException;
use PDO;

class ImmunizationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function forChild(int $childId): array
    {
        $statement = $this->pdo->prepare('SELECT i.*, u.name AS recorded_by, v.name AS vaccine_name, d.dose_number
            FROM immunizations i JOIN users u ON u.id = i.given_by
            JOIN schedule_doses d ON d.id = i.schedule_dose_id JOIN vaccines v ON v.id = d.vaccine_id
            WHERE i.child_id = :child_id ORDER BY i.date_given, i.id');
        $statement->execute(['child_id' => $childId]);

        return $statement->fetchAll();
    }

    public function insert(array $data): int
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Immunization creation requires a transaction.');
        }
        $statement = $this->pdo->prepare('INSERT INTO immunizations (child_id, schedule_dose_id, date_given,
            lot_number, site, given_elsewhere, place_given, given_by) VALUES (:child_id, :schedule_dose_id,
            :date_given, :lot_number, :site, :given_elsewhere, :place_given, :given_by)');
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<int> $childIds */
    public function forChildren(array $childIds): array
    {
        if ($childIds === []) {
            return [];
        }
        foreach ($childIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('Invalid child identity.');
            }
        }
        $placeholders = implode(', ', array_fill(0, count($childIds), '?'));
        $statement = $this->pdo->prepare('SELECT child_id, schedule_dose_id, date_given FROM immunizations
            WHERE child_id IN (' . $placeholders . ') ORDER BY child_id, id');
        $statement->execute($childIds);
        $records = [];
        foreach ($statement->fetchAll() as $row) {
            $records[$row['child_id']][] = $row;
        }

        return $records;
    }
}
