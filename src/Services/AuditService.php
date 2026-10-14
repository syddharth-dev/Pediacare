<?php

declare(strict_types=1);

namespace Pediacare\Services;

use Pediacare\Repositories\AuditRepository;

final class AuditService
{
    public function __construct(private readonly AuditRepository $audit)
    {
    }

    public function search(array $filters): array
    {
        $entries = $this->audit->search($filters);
        foreach ($entries as &$entry) {
            $changes = json_decode($entry['changes'], false, 512, JSON_THROW_ON_ERROR);
            $entry['changes'] = json_encode($changes, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        unset($entry);

        return $entries;
    }

    public function entities(): array
    {
        return $this->audit->entities();
    }
}
