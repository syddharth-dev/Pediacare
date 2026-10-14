<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth;

use InvalidArgumentException;

final class Bmi
{
    public static function calculate(float $weightKg, float $heightCm): float
    {
        if (!is_finite($weightKg) || $weightKg <= 0 || !is_finite($heightCm) || $heightCm <= 0) {
            throw new InvalidArgumentException('BMI requires positive, finite weight and length or height.');
        }

        // WHO Anthro manual v3.2.2, §3.3.1: kg / m², using position-adjusted length/height.
        // https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/software/anthro-pc-manual-v322.pdf
        $heightM = $heightCm / 100;
        $squaredHeight = $heightM * $heightM;
        if ($squaredHeight === 0.0 || !is_finite($squaredHeight)) {
            throw new InvalidArgumentException('Length or height is outside the calculable range.');
        }
        $bmi = $weightKg / $squaredHeight;
        if (!is_finite($bmi) || $bmi <= 0) {
            throw new InvalidArgumentException('BMI is outside the calculable range.');
        }

        return $bmi;
    }
}
