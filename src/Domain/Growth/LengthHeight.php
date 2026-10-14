<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth;

use InvalidArgumentException;
use Pediacare\Domain\Enums\MeasurementPosition;

final class LengthHeight
{
    public static function adjust(float $measuredCm, MeasurementPosition $position, AgeInMonths $age): float
    {
        if (!is_finite($measuredCm) || $measuredCm <= 0) {
            throw new InvalidArgumentException('Length or height must be positive and finite.');
        }

        // WHO Anthro manual v3.2.2, §3.2.3: length through 730 days, height from 731; correction ±0.7 cm.
        // https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/software/anthro-pc-manual-v322.pdf
        $expected = $age->days < 731 ? MeasurementPosition::LYING : MeasurementPosition::STANDING;
        $adjusted = $measuredCm;
        if ($position !== $expected) {
            $adjusted += $expected === MeasurementPosition::LYING ? 0.7 : -0.7;
        }
        if (!is_finite($adjusted) || $adjusted <= 0) {
            throw new InvalidArgumentException('Adjusted length or height must be positive and finite.');
        }

        return $adjusted;
    }
}
