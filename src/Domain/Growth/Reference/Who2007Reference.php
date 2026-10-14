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
use Pediacare\Domain\Growth\LmsTable;
use Pediacare\Domain\Growth\LmsZScore;

final class Who2007Reference implements GrowthReference
{
    public function __construct(private readonly LmsTable $table)
    {
    }

    public function supports(AgeInMonths $age): bool
    {
        // WHO AnthroPlus manual §3.2.1; official tables include 229 only as an interpolation endpoint.
        // https://www.who.int/docs/default-source/child-growth/growth-reference-5-19-years/who-anthroplus-manual.pdf
        return $age->whoMonths() >= 61 && $age->whoMonths() < 229;
    }

    public function calculate(
        Sex $sex,
        AgeInMonths $age,
        float $weightKg,
        float $heightCm,
        MeasurementPosition $position,
    ): GrowthResult {
        if (!$this->supports($age)) {
            throw new OutOfRangeException('Age is outside WHO2007 coverage.');
        }
        // AnthroPlus manual §3.2.3: above 60 completed WHO months use height without length conversion.
        // https://www.who.int/docs/default-source/child-growth/growth-reference-5-19-years/who-anthroplus-manual.pdf
        $bmi = Bmi::calculate($weightKg, $heightCm);
        $scores = ['WFA' => null];
        $values = ['HFA' => $heightCm, 'BFA' => $bmi];
        // WHO2007 WFA covers completed WHO months 61–120, with 121 as interpolation endpoint.
        // https://www.who.int/tools/growth-reference-data-for-5to19-years/indicators/weight-for-age
        if ($age->whoMonths() < 121) {
            $values['WFA'] = $weightKg;
        }
        foreach ($values as $indicator => $value) {
            $parameters = $this->table->at('WHO2007', $indicator, $sex, $age->whoMonths());
            if ($parameters === null) {
                throw new LogicException('Required WHO age parameters are missing.');
            }
            $scores[$indicator] = LmsZScore::calculate($value, $parameters, $indicator !== 'HFA');
        }

        return new GrowthResult('WHO2007', $bmi, $heightCm, $scores['WFA'], $scores['HFA'], null, $scores['BFA']);
    }
}
