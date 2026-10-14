<?php

declare(strict_types=1);

namespace Pediacare\Support;

final class Permissions
{
    // Spec §3: permissions are explicit; unlisted actions and roles are denied.
    private const MATRIX = [
        'session.logout' => ['ADMIN', 'STAFF', 'BHW', 'VIEWER'],
        'children.read' => ['ADMIN', 'STAFF', 'BHW'],
        'children.create' => ['ADMIN', 'STAFF', 'BHW'],
        'children.update' => ['ADMIN', 'STAFF'],
        'children.archive' => ['ADMIN', 'STAFF'],
        'children.restore' => ['ADMIN', 'STAFF'],
        'measurements.create' => ['ADMIN', 'STAFF', 'BHW'],
        'measurements.update' => ['ADMIN', 'STAFF'],
        'immunizations.create' => ['ADMIN', 'STAFF'],
        'immunizations.update' => ['ADMIN', 'STAFF'],
        'visits.create' => ['ADMIN', 'STAFF', 'BHW'],
        'visits.update' => ['ADMIN', 'STAFF'],
        'reports.aggregate' => ['ADMIN', 'STAFF', 'VIEWER'],
        'users.manage' => ['ADMIN'],
        'puroks.manage' => ['ADMIN'],
        'schedule.manage' => ['ADMIN'],
        'audit.read' => ['ADMIN'],
    ];

    /** @return list<string> */
    public static function roles(string $permission): array
    {
        return self::MATRIX[$permission] ?? [];
    }

    public static function allows(string $role, string $permission): bool
    {
        return in_array($role, self::roles($permission), true);
    }
}
