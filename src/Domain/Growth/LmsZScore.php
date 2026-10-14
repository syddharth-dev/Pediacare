<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth;

use InvalidArgumentException;

final class LmsZScore
{
    public static function calculate(float $value, LmsParameters $parameters, bool $restricted): float
    {
        if (!is_finite($value) || $value <= 0) {
            throw new InvalidArgumentException('Measurement must be positive and finite.');
        }
        $ratio = $value / $parameters->m;
        if (!is_finite($ratio) || $ratio <= 0) {
            throw new InvalidArgumentException('Measurement is outside the numeric range.');
        }
        // WHO computation guide, pp. 1–3: LMS, log limit at L=0, and fixed SD2–SD3 tail distances.
        // https://www.who.int/docs/default-source/child-growth/growth-reference-5-19-years/computation.pdf
        $z = $parameters->l === 0.0
            ? log($ratio) / $parameters->s
            : expm1($parameters->l * log($ratio)) / ($parameters->l * $parameters->s);
        if ($restricted && abs($z) > 3) {
            $direction = $z > 0 ? 1 : -1;
            $sd3 = self::valueAt($direction * 3, $parameters);
            $sd2 = self::valueAt($direction * 2, $parameters);
            $distance = abs($sd3 - $sd2);
            if (!is_finite($distance) || $distance <= 0) {
                throw new InvalidArgumentException('Invalid LMS tail distance.');
            }
            $z = $direction * 3 + ($value - $sd3) / $distance;
        }
        if (!is_finite($z)) {
            throw new InvalidArgumentException('Z-score is outside the numeric range.');
        }

        return $z;
    }

    public static function valueAt(int $z, LmsParameters $parameters): float
    {
        if ($parameters->l === 0.0) {
            return $parameters->m * exp($parameters->s * $z);
        }
        $base = 1 + $parameters->l * $parameters->s * $z;
        if ($base <= 0) {
            throw new InvalidArgumentException('Invalid LMS tail parameters.');
        }

        return $parameters->m * pow($base, 1 / $parameters->l);
    }
}
