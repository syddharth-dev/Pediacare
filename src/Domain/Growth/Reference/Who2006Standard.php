<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth\Reference;

use LogicException;
use OutOfRangeException;
use Pediacare\Domain\Enums\MeasurementPosition;
use Pediacare\Domain\Enums\Sex;
use Pediacare\Domain\Growth\AgeInMonths;
use Pediacare\Domain\Growth\Bmi;
use Pediacare\Domain\Growth\GrowthResult;
use Pediacare\Domain\Growth\LengthHeight;
use Pediacare\Domain\Growth\LmsTable;
use Pediacare\Domain\Growth\LmsZScore;

final class Who2006Standard implements GrowthReference
{
    public function __construct(private readonly LmsTable $table)
    {
    }

    public function supports(AgeInMonths $age): bool
    {
        // Official WHO Anthro daily LMS tables cover days 0–1856; see database/who/README.md.
        return $age->days <= 1856;
    }

    public function calculate(
        Sex $sex,
        AgeInMonths $age,
        float $weightKg,
        float $heightCm,
        MeasurementPosition $position,
    ): GrowthResult {
        if (!$this->supports($age)) {
            throw new OutOfRangeException('Age is outside WHO2006 coverage.');
        }
        $height = LengthHeight::adjust($heightCm, $position, $age);
        $bmi = Bmi::calculate($weightKg, $height);
        $scores = [];
        foreach (['WFA' => $weightKg, 'HFA' => $height, 'BFA' => $bmi] as $indicator => $value) {
            $parameters = $this->table->at('WHO2006', $indicator, $sex, $age->days);
            if ($parameters === null) {
                throw new LogicException('Required WHO age parameters are missing.');
            }
            $scores[$indicator] = LmsZScore::calculate($value, $parameters, $indicator !== 'HFA');
        }
        // WHO Anthro manual §3.2.3: WFL through day 730, WFH from day 731.
        // https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/software/anthro-pc-manual-v322.pdf
        $parameters = $this->table->at('WHO2006', $age->days < 731 ? 'WFL' : 'WFH', $sex, $height);

        return new GrowthResult(
            'WHO2006', $bmi, $height, $scores['WFA'], $scores['HFA'],
            $parameters === null ? null : LmsZScore::calculate($weightKg, $parameters, true), $scores['BFA'],
        );
    }
}
