<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth;

use Pediacare\Domain\Enums\NutritionStatus;

final class GrowthClassification
{
    /** @param list<string> $implausibleIndicators */
    public function __construct(
        public readonly ?NutritionStatus $wfaStatus,
        public readonly ?NutritionStatus $hfaStatus,
        public readonly ?NutritionStatus $wfhStatus,
        public readonly ?NutritionStatus $bmiStatus,
        public readonly array $implausibleIndicators,
    ) {
    }

    public function needsConfirmation(): bool
    {
        return $this->implausibleIndicators !== [];
    }
}
