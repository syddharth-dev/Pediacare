<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth;

use InvalidArgumentException;

final class LmsParameters
{
    public function __construct(public readonly float $l, public readonly float $m, public readonly float $s)
    {
        if (!is_finite($l) || !is_finite($m) || !is_finite($s) || $m <= 0 || $s <= 0) {
            throw new InvalidArgumentException('Invalid LMS parameters.');
        }
    }
}
