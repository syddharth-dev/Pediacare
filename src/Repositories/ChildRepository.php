<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use LogicException;
use PDO;

class ChildRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function duplicates(array $data): array
    {
        $fullName = implode(' ', array_filter([
            $data['first_name'], $data['middle_name'], $data['last_name'], $data['suffix'],
        ], static fn (?string $part): bool => $part !== null && $part !== ''));
        $statement = $this->pdo->prepare(
            "SELECT id, record_no, archived_at FROM children WHERE date_of_birth = :birth_date "
            . "AND TRIM(CONCAT_WS(' ', first_name, NULLIF(middle_name, ''), last_name, NULLIF(suffix, ''))) = :full_name "
            . 'ORDER BY id'
        );
        $statement->execute(['birth_date' => $data['date_of_birth'], 'full_name' => $fullName]);

        return $statement->fetchAll();
    }

    public function immunizationBatch(int $afterId, ?int $purokId): array
    {
        $statement = $this->pdo->prepare("SELECT c.id, c.record_no, c.date_of_birth, c.purok_id,
            CONCAT_WS(' ', c.first_name, NULLIF(c.middle_name, ''), c.last_name, NULLIF(c.suffix, '')) AS full_name,
            p.name AS purok_name FROM children c JOIN puroks p ON p.id = c.purok_id
            WHERE c.archived_at IS NULL AND c.id > :after_id"
            . ($purokId === null ? '' : ' AND c.purok_id = :purok_id') . ' ORDER BY c.id LIMIT 200');
        $parameters = ['after_id' => $afterId];
        if ($purokId !== null) {
            $parameters['purok_id'] = $purokId;
        }
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    public function findById(int $id, string $today): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT c.*, CONCAT_WS(' ', c.first_name, NULLIF(c.middle_name, ''), c.last_name, "
            . "NULLIF(c.suffix, '')) AS full_name, p.name AS purok_name, "
            . 'TIMESTAMPDIFF(MONTH, c.date_of_birth, :today) AS age_months '
            . 'FROM children c JOIN puroks p ON p.id = c.purok_id WHERE c.id = :id'
        );
        $statement->execute(['id' => $id, 'today' => $today]);
        $child = $statement->fetch();

        return $child === false ? null : $child;
    }

    public function insert(array $data): int
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Child registration requires a transaction.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO children (record_no, first_name, middle_name, last_name, suffix, sex, date_of_birth, '
            . 'place_of_birth, purok_id, address, mother_name, father_name, guardian_name, guardian_relation, '
            . 'contact_no, philhealth_no, birth_weight_kg, birth_length_cm, notes, created_by) '
            . 'VALUES (:record_no, :first_name, :middle_name, :last_name, :suffix, :sex, :date_of_birth, '
            . ':place_of_birth, :purok_id, :address, :mother_name, :father_name, :guardian_name, :guardian_relation, '
            . ':contact_no, :philhealth_no, :birth_weight_kg, :birth_length_cm, :notes, :created_by)'
        );
        $statement->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function count(array $filters, string $today): int
    {
        [$where, $parameters] = $this->conditions($filters, $today);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM children c WHERE ' . $where);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    public function lockById(int $id): ?array
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Child changes require a transaction.');
        }
        $statement = $this->pdo->prepare('SELECT * FROM children WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $child = $statement->fetch();

        return $child === false ? null : $child;
    }

    public function update(int $id, array $data): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Child changes require a transaction.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE children SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name, '
            . 'suffix = :suffix, sex = :sex, date_of_birth = :date_of_birth, place_of_birth = :place_of_birth, '
            . 'purok_id = :purok_id, address = :address, mother_name = :mother_name, father_name = :father_name, '
            . 'guardian_name = :guardian_name, guardian_relation = :guardian_relation, contact_no = :contact_no, '
            . 'philhealth_no = :philhealth_no, birth_weight_kg = :birth_weight_kg, '
            . 'birth_length_cm = :birth_length_cm, notes = :notes WHERE id = :id'
        );
        $statement->execute($data + ['id' => $id]);
    }

    public function setArchive(int $id, ?string $archivedAt, ?string $reason): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Child changes require a transaction.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE children SET archived_at = :archived_at, archive_reason = :archive_reason WHERE id = :id'
        );
        $statement->execute(['id' => $id, 'archived_at' => $archivedAt, 'archive_reason' => $reason]);
    }

    public function search(array $filters, string $today, int $limit, int $offset): array
    {
        [$where, $parameters] = $this->conditions($filters, $today);
        $statement = $this->pdo->prepare(
            "SELECT c.id, c.record_no, CONCAT_WS(' ', c.first_name, NULLIF(c.middle_name, ''), "
            . "c.last_name, NULLIF(c.suffix, '')) AS full_name, c.sex, c.date_of_birth, "
            . 'TIMESTAMPDIFF(MONTH, c.date_of_birth, :age_date) AS age_months, '
            . 'c.guardian_name, p.name AS purok_name, c.archived_at '
            . 'FROM children c JOIN puroks p ON p.id = c.purok_id WHERE ' . $where
            . ' ORDER BY c.last_name, c.first_name, c.id LIMIT :row_limit OFFSET :row_offset'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':age_date', $today);
        $statement->bindValue(':row_limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':row_offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** SQL fragments are fixed; all filter values remain bound parameters. */
    private function conditions(array $filters, string $today): array
    {
        $conditions = ['c.date_of_birth <= :today'];
        $parameters = ['today' => $today];
        if (!$filters['include_archived']) {
            $conditions[] = 'c.archived_at IS NULL';
        }
        if ($filters['q'] !== '') {
            $pattern = '%' . strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $conditions[] = "(CONCAT_WS(' ', c.first_name, NULLIF(c.middle_name, ''), c.last_name, "
                . "NULLIF(c.suffix, '')) LIKE :name ESCAPE '!' OR c.record_no LIKE :record ESCAPE '!' "
                . "OR c.guardian_name LIKE :guardian ESCAPE '!')";
            $parameters += ['name' => $pattern, 'record' => $pattern, 'guardian' => $pattern];
        }
        if ($filters['purok_id'] !== '') {
            $conditions[] = 'c.purok_id = :purok';
            $parameters['purok'] = (int) $filters['purok_id'];
        }
        if ($filters['sex'] !== '') {
            $conditions[] = 'c.sex = :sex';
            $parameters['sex'] = $filters['sex'];
        }
        if ($filters['age_group'] !== '') {
            $conditions[] = $filters['age_group'] === '60+'
                ? 'TIMESTAMPDIFF(MONTH, c.date_of_birth, :filter_date) >= :min_months'
                : 'TIMESTAMPDIFF(MONTH, c.date_of_birth, :filter_date) BETWEEN :min_months AND :max_months';
            $parameters['filter_date'] = $today;
            $parameters['min_months'] = (int) $filters['age_group'];
            if ($filters['age_group'] !== '60+') {
                $parameters['max_months'] = $parameters['min_months'] + 11;
            }
        }

        return [implode(' AND ', $conditions), $parameters];
    }
}
