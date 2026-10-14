<?php

declare(strict_types=1);

namespace Pediacare\Domain\Enums;

enum DoseStatus: string
{
    case GIVEN = 'Given';
    case DUE = 'Due';
    case OVERDUE = 'Overdue';
    case UPCOMING = 'Upcoming';
}
