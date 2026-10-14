<?php

declare(strict_types=1);

namespace Pediacare\Domain\Enums;

enum MeasurementPosition: string
{
    case LYING = 'LYING';
    case STANDING = 'STANDING';
}
