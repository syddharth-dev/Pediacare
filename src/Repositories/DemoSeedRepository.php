<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use PDO;

final class DemoSeedRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function hasChildren(): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM children LIMIT 1');
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }
}
