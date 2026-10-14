<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use LogicException;
use PDO;
use PDOStatement;
use Pediacare\Domain\Growth\LmsTable;

class WhoLmsRepository
{
    private ?PDOStatement $insert = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function load(): LmsTable
    {
        $statement = $this->pdo->prepare(
            'SELECT reference, indicator, sex, age_or_size, l, m, s FROM who_lms
             ORDER BY reference, indicator, sex, age_or_size'
        );
        $statement->execute();

        return new LmsTable($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array{reference: string, indicator: string, sex: string, age_or_size: string, l: string, m: string, s: string} $row */
    public function upsert(array $row): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('WHO reference imports require a transaction.');
        }

        $this->insert ??= $this->pdo->prepare(
            'INSERT INTO who_lms (reference, indicator, sex, age_or_size, l, m, s)
             VALUES (:reference, :indicator, :sex, :age_or_size, :l, :m, :s)
             ON DUPLICATE KEY UPDATE l = VALUES(l), m = VALUES(m), s = VALUES(s)'
        );
        $this->insert->execute($row);
    }
}
