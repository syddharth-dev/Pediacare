<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth;

use InvalidArgumentException;
use Pediacare\Domain\Enums\NutritionStatus;

final class Classifier
{
    public static function classify(GrowthResult $result): GrowthClassification
    {
        return new GrowthClassification(
            self::weightForAge($result->wfaZ),
            self::heightForAge($result->hfaZ),
            self::weightForHeight($result->wfhZ),
            self::bmiForAge($result->bmiZ, $result->reference),
            ImplausibleValues::indicators($result),
        );
    }

    public static function weightForAge(?float $z): ?NutritionStatus
    {
        self::validate($z);
        // Project-approved bands: specs/001-pediacare-mvp/plan.md §6.3; high WFA is not an overweight diagnosis.
        // WHO training, Session 15: https://iris.who.int/bitstream/10665/77944/4/9789241504812_Participants_manual_eng.pdf
        return match (true) {
            $z === null => null,
            $z < -3 => NutritionStatus::SEVERELY_UNDERWEIGHT,
            $z < -2 => NutritionStatus::UNDERWEIGHT,
            $z <= 2 => NutritionStatus::NORMAL,
            default => NutritionStatus::HIGH_WEIGHT_FOR_AGE,
        };
    }

    public static function heightForAge(?float $z): ?NutritionStatus
    {
        self::validate($z);
        // Project bands and endpoints: plan.md §6.3; WHO training, Session 15 (URL above).
        return match (true) {
            $z === null => null,
            $z < -3 => NutritionStatus::SEVERELY_STUNTED,
            $z < -2 => NutritionStatus::STUNTED,
            $z <= 3 => NutritionStatus::NORMAL,
            default => NutritionStatus::TALL,
        };
    }

    public static function weightForHeight(?float $z): ?NutritionStatus
    {
        self::validate($z);
        // Project bands and endpoints: plan.md §6.3; WHO training, Session 15 (URL above).
        // The approved MVP Normal band extends through +2; possible risk above +1 is not a separate label.
        return match (true) {
            $z === null => null,
            $z < -3 => NutritionStatus::SEVERELY_WASTED,
            $z < -2 => NutritionStatus::WASTED,
            $z <= 2 => NutritionStatus::NORMAL,
            $z <= 3 => NutritionStatus::OVERWEIGHT,
            default => NutritionStatus::OBESE,
        };
    }

    public static function bmiForAge(?float $z, string $reference): ?NutritionStatus
    {
        if (!in_array($reference, ['WHO2006', 'WHO2007'], true)) {
            throw new InvalidArgumentException('Unknown growth reference.');
        }
        if ($reference === 'WHO2006') {
            // Approved plan.md §6.3: WHO2006 BMI uses the WFL/WFH bands and labels.
            return self::weightForHeight($z);
        }
        self::validate($z);
        // Project bands: plan.md §6.3; WHO AnthroPlus manual §3.3 (BMI-for-age 61–228 WHO months).
        // https://www.who.int/docs/default-source/child-growth/growth-reference-5-19-years/who-anthroplus-manual.pdf
        return match (true) {
            $z === null => null,
            $z < -3 => NutritionStatus::SEVERE_THINNESS,
            $z < -2 => NutritionStatus::THINNESS,
            $z <= 1 => NutritionStatus::NORMAL,
            $z <= 2 => NutritionStatus::OVERWEIGHT,
            default => NutritionStatus::OBESE,
        };
    }

    private static function validate(?float $z): void
    {
        if ($z !== null && !is_finite($z)) {
            throw new InvalidArgumentException('Z-score must be finite or unavailable.');
        }
    }
}
