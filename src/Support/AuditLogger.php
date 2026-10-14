<?php

declare(strict_types=1);

namespace Pediacare\Support;

use InvalidArgumentException;
use Pediacare\Domain\Enums\AuditAction;
use Pediacare\Repositories\AuditRepository;

final class AuditLogger
{
    public function __construct(private readonly AuditRepository $repository, private readonly ?string $ipAddress = null)
    {
        if ($ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('Audit IP address is invalid.');
        }
    }

    public function log(int $userId, AuditAction $action, string $entity, int $entityId, array $before, array $after): void
    {
        if ($userId < 1 || $entityId < 1 || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $entity) !== 1) {
            throw new InvalidArgumentException('Audit identity is invalid.');
        }

        $old = [];
        $new = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            if (!is_string($field) || $field === '') {
                throw new InvalidArgumentException('Audit field names are invalid.');
            }

            $wasPresent = array_key_exists($field, $before);
            $isPresent = array_key_exists($field, $after);

            if ($wasPresent && $isPresent && $before[$field] === $after[$field]) {
                continue;
            }

            if ($wasPresent) {
                $old[$field] = $before[$field];
            }

            if ($isPresent) {
                $new[$field] = $after[$field];
            }
        }

        // Empty objects preserve the distinction between a missing field and an explicit null.
        $changes = json_encode(['before' => (object) $old, 'after' => (object) $new],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->repository->insert($userId, $action->value, $entity, $entityId, $changes, $this->ipAddress);
    }
}
