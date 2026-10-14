<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use PDO;

class ReportRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function malnutritionCount(array $filters): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM children c JOIN measurements m ON m.child_id = c.id '
            . $this->malnutritionWhere($filters));
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function malnutritionPage(array $filters, int $limit, int $offset): array
    {
        $statement = $this->pdo->prepare('SELECT c.id, c.record_no, c.first_name, c.middle_name, c.last_name,
            c.suffix, c.sex, c.archived_at, p.name AS purok_name, m.measured_at,
            m.wfa_status, m.hfa_status, m.wfh_status, m.bmi_status
            FROM children c JOIN measurements m ON m.child_id = c.id JOIN puroks p ON p.id = c.purok_id '
            . $this->malnutritionWhere($filters) . ' ORDER BY c.last_name, c.first_name, c.id LIMIT :limit OFFSET :offset');
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    private function malnutritionWhere(array $filters): string
    {
        // spec.md §4.6: any saved non-Normal indicator on the latest measurement; null is unavailable.
        return 'WHERE (m.wfa_status <> \'Normal\' OR m.hfa_status <> \'Normal\'
            OR m.wfh_status <> \'Normal\' OR m.bmi_status <> \'Normal\')'
            . ($filters['include_archived'] === '1' ? '' : ' AND c.archived_at IS NULL') . '
            AND NOT EXISTS (SELECT 1 FROM measurements newer WHERE newer.child_id = m.child_id
                AND (newer.measured_at > m.measured_at OR (newer.measured_at = m.measured_at AND newer.id > m.id)))';
    }

    public function monthlyDoses(array $filters): array
    {
        $statement = $this->pdo->prepare('SELECT v.name AS vaccine_name,
            COUNT(CASE WHEN records.sex = \'M\' THEN 1 END) AS male_count,
            COUNT(CASE WHEN records.sex = \'F\' THEN 1 END) AS female_count
            FROM vaccines v LEFT JOIN (SELECT d.vaccine_id, c.sex FROM immunizations i
                JOIN schedule_doses d ON d.id = i.schedule_dose_id JOIN children c ON c.id = i.child_id
                WHERE i.date_given BETWEEN :from_date AND :to_date'
                . ($filters['include_archived'] === '1' ? '' : ' AND c.archived_at IS NULL') . '
            ) records ON records.vaccine_id = v.id
            GROUP BY v.id, v.name ORDER BY v.name, v.id');
        $statement->execute(['from_date' => $filters['from'], 'to_date' => $filters['to']]);

        return $statement->fetchAll();
    }

    public function monthlyFic(array $filters): array
    {
        // plan.md §6.8 / AC-08: completion is the last required dose date under the current configuration.
        $statement = $this->pdo->prepare('SELECT
            COUNT(CASE WHEN completed.sex = \'M\' THEN 1 END) AS male_count,
            COUNT(CASE WHEN completed.sex = \'F\' THEN 1 END) AS female_count
            FROM (SELECT c.id, c.sex FROM children c JOIN immunizations i ON i.child_id = c.id
                JOIN schedule_doses d ON d.id = i.schedule_dose_id WHERE d.counts_toward_fic = 1'
                . ($filters['include_archived'] === '1' ? '' : ' AND c.archived_at IS NULL') . '
                GROUP BY c.id, c.sex
                HAVING COUNT(DISTINCT d.id) = (SELECT COUNT(*) FROM schedule_doses WHERE counts_toward_fic = 1)
                AND MAX(i.date_given) BETWEEN :from_date AND :to_date) completed');
        $statement->execute(['from_date' => $filters['from'], 'to_date' => $filters['to']]);

        return $statement->fetch();
    }

    public function nutritionalCounts(array $filters, bool $byPurok): array
    {
        // plan.md §6.7 / AC-10: choose the latest in-period measurement before applying calendar age eligibility.
        $dimensions = 'c.sex, m.wfa_status, m.hfa_status, m.wfh_status, m.bmi_status';
        $purok = $byPurok ? ', c.purok_id, p.name AS purok_name' : '';
        $statement = $this->pdo->prepare('SELECT ' . $dimensions . $purok . ', COUNT(*) AS child_count
            FROM measurements m JOIN children c ON c.id = m.child_id'
            . ($byPurok ? ' JOIN puroks p ON p.id = c.purok_id' : '') . '
            WHERE m.measured_at BETWEEN :from_date AND :to_date
            AND m.measured_at >= c.date_of_birth
            AND TIMESTAMPDIFF(MONTH, c.date_of_birth, m.measured_at) BETWEEN 0 AND 59'
            . ($filters['include_archived'] === '1' ? '' : ' AND c.archived_at IS NULL') . '
            AND NOT EXISTS (SELECT 1 FROM measurements newer WHERE newer.child_id = m.child_id
                AND newer.measured_at <= :latest_end AND (newer.measured_at > m.measured_at
                    OR (newer.measured_at = m.measured_at AND newer.id > m.id)))
            GROUP BY ' . $dimensions . ($byPurok ? ', c.purok_id, p.name' : '')
            . ($byPurok ? ' ORDER BY p.name, c.purok_id, c.sex' : ' ORDER BY c.sex'));
        $statement->execute(['from_date' => $filters['from'], 'to_date' => $filters['to'], 'latest_end' => $filters['to']]);

        return $statement->fetchAll();
    }
}
