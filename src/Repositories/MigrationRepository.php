<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use PDO;

class MigrationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function execute(string $sql): void
    {
        $this->pdo->prepare($sql)->execute();
    }

    /** @return list<string> */
    public function applied(): array
    {
        $statement = $this->pdo->prepare('SELECT filename FROM migrations ORDER BY id');
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function record(string $filename): void
    {
        $statement = $this->pdo->prepare('INSERT INTO migrations (filename) VALUES (:filename)');
        $statement->execute(['filename' => $filename]);
    }
}
