<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use LogicException;
use PDO;
use RuntimeException;

class RecordCounterRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function lock(int $year): int
    {
        $this->requireTransaction();
        // An upsert serializes first use of a year before the locking read.
        $statement = $this->pdo->prepare(
            'INSERT INTO record_counters (year, last_number) VALUES (:year, 0) '
            . 'ON DUPLICATE KEY UPDATE year = :existing_year'
        );
        $statement->execute(['year' => $year, 'existing_year' => $year]);
        $statement = $this->pdo->prepare('SELECT last_number FROM record_counters WHERE year = :year FOR UPDATE');
        $statement->execute(['year' => $year]);
        $number = $statement->fetchColumn();

        if ($number === false) {
            throw new RuntimeException('Record counter is missing.');
        }

        return (int) $number;
    }

    public function save(int $year, int $number): void
    {
        $this->requireTransaction();
        $statement = $this->pdo->prepare('UPDATE record_counters SET last_number = :number WHERE year = :year');
        $statement->execute(['number' => $number, 'year' => $year]);
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Record numbers require the caller\'s transaction.');
        }
    }
}
