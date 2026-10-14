<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth;

use DateTimeImmutable;
use InvalidArgumentException;

final class AgeInMonths
{
    private function __construct(public readonly int $months, public readonly int $days)
    {
    }

    public function whoMonths(): float
    {
        // WHO AnthroPlus manual §3.2.1: exact days / (365.25 / 12).
        // https://www.who.int/docs/default-source/child-growth/growth-reference-5-19-years/who-anthroplus-manual.pdf
        return $this->days / 30.4375;
    }

    public static function between(DateTimeImmutable $birthDate, DateTimeImmutable $measurementDate): self
    {
        // DATE values represent calendar dates, independent of time of day or timezone offsets.
        $birth = new DateTimeImmutable($birthDate->format('Y-m-d') . 'T00:00:00+00:00');
        $measured = new DateTimeImmutable($measurementDate->format('Y-m-d') . 'T00:00:00+00:00');
        if ($measured < $birth) {
            throw new InvalidArgumentException('Measurement date must be on or after birth date.');
        }

        $months = ((int) $measured->format('Y') - (int) $birth->format('Y')) * 12
            + (int) $measured->format('n') - (int) $birth->format('n');
        if ((int) $measured->format('j') < (int) $birth->format('j')) {
            --$months;
        }

        return new self($months, (int) $birth->diff($measured)->days);
    }
}
