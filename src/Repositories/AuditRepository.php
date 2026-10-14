<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use LogicException;
use PDO;

class AuditRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function search(array $filters): array
    {
        $conditions = [];
        $parameters = [];

        foreach (['user_id' => 'a.user_id = :user_id', 'entity' => 'a.entity = :entity',
            'from' => 'a.created_at >= :from', 'to' => 'a.created_at <= :to'] as $key => $condition) {
            if ($filters[$key] !== '') {
                $conditions[] = $condition;
                $parameters[$key] = match ($key) {
                    'from' => $filters[$key] . ' 00:00:00',
                    'to' => $filters[$key] . ' 23:59:59',
                    default => $filters[$key],
                };
            }
        }

        $statement = $this->pdo->prepare(
            'SELECT a.*, u.name AS user_name FROM audit_logs a JOIN users u ON u.id = a.user_id'
            . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
            . ' ORDER BY a.created_at DESC, a.id DESC'
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    public function entities(): array
    {
        $statement = $this->pdo->prepare('SELECT DISTINCT entity FROM audit_logs ORDER BY entity');
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function insert(
        int $userId,
        string $action,
        string $entity,
        int $entityId,
        string $changes,
        ?string $ipAddress
    ): void {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Audit entries require the caller\'s transaction.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity, entity_id, changes, ip_address) '
            . 'VALUES (:user_id, :action, :entity, :entity_id, :changes, :ip_address)'
        );
        $statement->execute([
            'user_id' => $userId, 'action' => $action, 'entity' => $entity,
            'entity_id' => $entityId, 'changes' => $changes, 'ip_address' => $ipAddress,
        ]);
    }
}
