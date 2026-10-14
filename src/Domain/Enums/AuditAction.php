<?php

declare(strict_types=1);

namespace Pediacare\Domain\Enums;

enum AuditAction: string
{
    case CREATE = 'CREATE';
    case UPDATE = 'UPDATE';
    case ARCHIVE = 'ARCHIVE';
    case RESTORE = 'RESTORE';
}
