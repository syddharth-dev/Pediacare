<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use LogicException;
use PDO;

class MeasurementRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function history(int $childId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.*, u.name AS measured_by_name FROM measurements m
             JOIN users u ON u.id = m.measured_by WHERE m.child_id = :child_id
             ORDER BY m.measured_at DESC, m.id DESC'
        );
        $statement->execute(['child_id' => $childId]);

        return $statement->fetchAll();
    }

    public function insert(array $data): int
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Measurement creation requires a transaction.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO measurements (child_id, measured_at, weight_kg, height_cm, position, muac_cm, age_months,
             bmi, wfa_z, hfa_z, wfh_z, bmi_z, wfa_status, hfa_status, wfh_status, bmi_status,
             implausible_confirmed, measured_by)
             VALUES (:child_id, :measured_at, :weight_kg, :height_cm, :position, :muac_cm, :age_months,
             :bmi, :wfa_z, :hfa_z, :wfh_z, :bmi_z, :wfa_status, :hfa_status, :wfh_status, :bmi_status,
             :implausible_confirmed, :measured_by)'
        );
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }
}
