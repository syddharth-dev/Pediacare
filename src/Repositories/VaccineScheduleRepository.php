<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use LogicException;
use PDO;

class VaccineScheduleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare('SELECT id, code, name, active FROM vaccines ORDER BY name, id');
        $statement->execute();
        $vaccines = [];
        foreach ($statement->fetchAll() as $row) {
            $vaccines[$row['id']] = $row + ['doses' => []];
        }
        $statement = $this->pdo->prepare('SELECT * FROM schedule_doses ORDER BY sort_order, dose_number, id');
        $statement->execute();
        foreach ($statement->fetchAll() as $row) {
            if (isset($vaccines[$row['vaccine_id']])) {
                $vaccines[$row['vaccine_id']]['doses'][] = $row;
            }
        }

        return array_values($vaccines);
    }

    public function vaccine(int $id, bool $lock = false): ?array
    {
        if ($lock) {
            $this->requireTransaction();
        }
        $statement = $this->pdo->prepare('SELECT id, code, name, active FROM vaccines WHERE id = :id' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function dose(int $id, bool $lock = false): ?array
    {
        if ($lock) {
            $this->requireTransaction();
        }
        $statement = $this->pdo->prepare('SELECT d.*, v.name AS vaccine_name, v.code AS vaccine_code
            FROM schedule_doses d JOIN vaccines v ON v.id = d.vaccine_id WHERE d.id = :id' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function doseUsed(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM immunizations WHERE schedule_dose_id = :id LIMIT 1');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() !== false;
    }

    public function insertVaccine(array $data): int
    {
        $this->requireTransaction();
        $statement = $this->pdo->prepare('INSERT INTO vaccines (code, name, active) VALUES (:code, :name, :active)');
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateVaccine(int $id, array $data): void
    {
        $this->requireTransaction();
        $statement = $this->pdo->prepare('UPDATE vaccines SET name = :name, active = :active WHERE id = :id');
        $statement->execute($data + ['id' => $id]);
    }

    public function insertDose(array $data): int
    {
        $this->requireTransaction();
        $statement = $this->pdo->prepare('INSERT INTO schedule_doses (vaccine_id, dose_number, recommended_age_days,
            recommended_age_months, grace_days, min_interval_days, min_interval_months, counts_toward_fic, sort_order)
            VALUES (:vaccine_id, :dose_number, :recommended_age_days, :recommended_age_months, :grace_days,
            :min_interval_days, :min_interval_months, :counts_toward_fic, :sort_order)');
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateDose(int $id, array $data): void
    {
        $this->requireTransaction();
        $statement = $this->pdo->prepare('UPDATE schedule_doses SET dose_number = :dose_number,
            recommended_age_days = :recommended_age_days, recommended_age_months = :recommended_age_months,
            grace_days = :grace_days, min_interval_days = :min_interval_days, min_interval_months = :min_interval_months,
            counts_toward_fic = :counts_toward_fic, sort_order = :sort_order WHERE id = :id');
        $statement->execute($data + ['id' => $id]);
    }

    public function ensureVaccine(string $code, string $name): int
    {
        $this->requireTransaction();
        $statement = $this->pdo->prepare(
            'INSERT INTO vaccines (code, name, active) VALUES (:code, :name, 1)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $statement->execute(['code' => $code, 'name' => $name]);

        return (int) $this->pdo->lastInsertId();
    }

    public function ensureDose(array $data): void
    {
        $this->requireTransaction();
        $statement = $this->pdo->prepare(
            'INSERT INTO schedule_doses (vaccine_id, dose_number, recommended_age_days,
             recommended_age_months, grace_days, min_interval_days, min_interval_months, counts_toward_fic, sort_order)
             VALUES (:vaccine_id, :dose_number, :recommended_age_days, :recommended_age_months,
             :grace_days, :min_interval_days, :min_interval_months, :counts_toward_fic, :sort_order)
             ON DUPLICATE KEY UPDATE id = id'
        );
        $statement->execute($data);
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Vaccine schedule writes and locks require a transaction.');
        }
    }
}
