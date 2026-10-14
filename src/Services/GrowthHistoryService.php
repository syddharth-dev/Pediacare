<?php

declare(strict_types=1);

namespace Pediacare\Services;

use DateTimeImmutable;
use Pediacare\Domain\Enums\Sex;
use Pediacare\Domain\Growth\AgeInMonths;
use Pediacare\Domain\Growth\BmiCurves;
use Pediacare\Repositories\MeasurementRepository;
use Pediacare\Repositories\WhoLmsRepository;

final class GrowthHistoryService
{
    public function __construct(
        private readonly MeasurementRepository $measurements,
        private readonly WhoLmsRepository $references
    ) {
    }

    public function forChild(array $child): array
    {
        $history = $this->measurements->history((int) $child['id']);
        if ($history === []) {
            return ['history' => [], 'chartJson' => null, 'omitted' => 0];
        }
        $birth = new DateTimeImmutable($child['date_of_birth']);
        $points = [];
        $omitted = 0;
        foreach (array_reverse($history) as $measurement) {
            $date = new DateTimeImmutable($measurement['measured_at']);
            // A later demographic correction must not erase or recompute saved clinical results.
            if ($date < $birth) {
                ++$omitted;
                continue;
            }
            $age = AgeInMonths::between($birth, $date);
            if ($age->whoMonths() >= 229) {
                ++$omitted;
                continue;
            }
            $points[] = ['x' => $age->whoMonths(), 'y' => (float) $measurement['bmi'],
                'date' => $measurement['measured_at'], 'confirmed' => (bool) $measurement['implausible_confirmed']];
        }
        $chart = null;
        if ($points !== []) {
            // WHO2007 supports completed WHO months through 228; see database/who/README.md.
            $maximum = min(6970 / 30.4375, max(6.0, max(array_column($points, 'x')) + 3));
            $chart = json_encode(['points' => $points, 'maximumMonths' => $maximum,
                'curves' => BmiCurves::points($this->references->load(), Sex::from($child['sex']), $maximum)],
                JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        }

        return ['history' => $history, 'chartJson' => $chart, 'omitted' => $omitted];
    }
}
