<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth;

final class GrowthResult
{
    public function __construct(
        public readonly string $reference,
        public readonly float $bmi,
        public readonly float $adjustedHeightCm,
        public readonly ?float $wfaZ,
        public readonly ?float $hfaZ,
        public readonly ?float $wfhZ,
        public readonly ?float $bmiZ,
    ) {
    }
}
