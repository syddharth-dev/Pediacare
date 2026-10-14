<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth;

use OutOfRangeException;
use Pediacare\Domain\Enums\MeasurementPosition;
use Pediacare\Domain\Enums\Sex;
use Pediacare\Domain\Growth\Reference\GrowthReference;

final class GrowthCalculator
{
    public function __construct(
        private readonly GrowthReference $standard,
        private readonly GrowthReference $reference,
    ) {
    }

    public function calculate(
        Sex $sex,
        AgeInMonths $age,
        float $weightKg,
        float $heightCm,
        MeasurementPosition $position,
    ): GrowthResult {
        foreach ([$this->standard, $this->reference] as $strategy) {
            if ($strategy->supports($age)) {
                return $strategy->calculate($sex, $age, $weightKg, $heightCm, $position);
            }
        }

        throw new OutOfRangeException('Age is outside WHO growth reference coverage.');
    }
}
