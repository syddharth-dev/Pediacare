<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth\Reference;

use Pediacare\Domain\Enums\MeasurementPosition;
use Pediacare\Domain\Enums\Sex;
use Pediacare\Domain\Growth\AgeInMonths;
use Pediacare\Domain\Growth\GrowthResult;

interface GrowthReference
{
    public function supports(AgeInMonths $age): bool;

    public function calculate(
        Sex $sex,
        AgeInMonths $age,
        float $weightKg,
        float $heightCm,
        MeasurementPosition $position,
    ): GrowthResult;
}
