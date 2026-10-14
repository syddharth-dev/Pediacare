<?php

declare(strict_types=1);

use Pediacare\Domain\Enums\NutritionStatus;
use Pediacare\Domain\Growth\Classifier;
use Pediacare\Domain\Growth\GrowthResult;
use Pediacare\Domain\Growth\ImplausibleValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrowthClassificationTest extends TestCase
{
    public static function categoryCases(): iterable
    {
        // Explicit expectations from the approved plan §6.3, including each boundary and either side.
        $scores = [-3.0001, -3.0, -2.9999, -2.0001, -2.0, -1.9999, 0.0, 1.0, 1.0001, 1.9999, 2.0, 2.0001, 2.9999, 3.0, 3.0001];
        $expected = [
            'weightForAge' => ['Severely underweight', 'Underweight', 'Underweight', 'Underweight', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'High weight-for-age', 'High weight-for-age', 'High weight-for-age', 'High weight-for-age'],
            'heightForAge' => ['Severely stunted', 'Stunted', 'Stunted', 'Stunted', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Tall'],
            'weightForHeight' => ['Severely wasted', 'Wasted', 'Wasted', 'Wasted', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Overweight', 'Overweight', 'Overweight', 'Obese'],
            'WHO2006' => ['Severely wasted', 'Wasted', 'Wasted', 'Wasted', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Normal', 'Overweight', 'Overweight', 'Overweight', 'Obese'],
            'WHO2007' => ['Severe thinness', 'Thinness', 'Thinness', 'Thinness', 'Normal', 'Normal', 'Normal', 'Normal', 'Overweight', 'Overweight', 'Overweight', 'Obese', 'Obese', 'Obese', 'Obese'],
        ];
        foreach ($expected as $classifier => $labels) {
            foreach ($scores as $index => $score) {
                yield $classifier . ':' . $score => [$classifier, $score, $labels[$index]];
            }
        }
    }

    #[DataProvider('categoryCases')]
    public function test_AC06_category_boundaries_match_approved_plan_without_rounding(string $classifier, float $z, string $expected): void
    {
        $status = str_starts_with($classifier, 'WHO')
            ? Classifier::bmiForAge($z, $classifier)
            : Classifier::$classifier($z);
        self::assertSame(NutritionStatus::from($expected), $status);
    }

    private static function growthResult(array $scores, string $reference = 'WHO2006'): GrowthResult
    {
        return new GrowthResult(
            $reference, 16, 80, $scores['wfa_z'] ?? null, $scores['hfa_z'] ?? null,
            $scores['wfh_z'] ?? null, $scores['bmi_z'] ?? null,
        );
    }

    public static function flagCases(): iterable
    {
        foreach (['wfa_z' => [-6.0, 5.0], 'hfa_z' => [-6.0, 6.0], 'wfh_z' => [-5.0, 5.0], 'bmi_z' => [-5.0, 5.0]] as $indicator => [$lower, $upper]) {
            foreach ([[$lower - 0.0001, true], [$lower, false], [$lower + 0.0001, false], [$upper - 0.0001, false], [$upper, false], [$upper + 0.0001, true]] as [$z, $flagged]) {
                yield $indicator . ':' . $z => [$indicator, $z, $flagged];
            }
        }
    }

    #[DataProvider('flagCases')]
    public function test_AC14_only_values_strictly_outside_the_flag_limits_need_confirmation(string $indicator, float $z, bool $flagged): void
    {
        foreach (['WHO2006', 'WHO2007'] as $reference) {
            $result = self::growthResult([$indicator => $z], $reference);
            self::assertSame($flagged ? [$indicator] : [], ImplausibleValues::indicators($result));
            self::assertSame($flagged, Classifier::classify($result)->needsConfirmation());
        }
    }

    public function test_AC06_unavailable_indicators_remain_null_and_are_not_classified_as_normal(): void
    {
        $classification = Classifier::classify(self::growthResult(['hfa_z' => 0, 'bmi_z' => 1.5], 'WHO2007'));
        self::assertNull($classification->wfaStatus);
        self::assertNull($classification->wfhStatus);
        self::assertSame(NutritionStatus::NORMAL, $classification->hfaStatus);
        self::assertSame(NutritionStatus::OVERWEIGHT, $classification->bmiStatus);
        self::assertSame([], $classification->implausibleIndicators);
        self::assertFalse($classification->needsConfirmation());
        $allMissing = Classifier::classify(self::growthResult([]));
        self::assertNull($allMissing->bmiStatus);
        self::assertNull($allMissing->hfaStatus);
        self::assertFalse($allMissing->needsConfirmation());
    }

    public function test_AC14_collects_all_flagged_indicators_without_discarding_scores_or_labels(): void
    {
        $result = self::growthResult(['wfa_z' => -6.001, 'hfa_z' => 6.001, 'wfh_z' => -5.001, 'bmi_z' => 5.001]);
        $classification = Classifier::classify($result);
        self::assertSame(['wfa_z', 'hfa_z', 'wfh_z', 'bmi_z'], $classification->implausibleIndicators);
        self::assertTrue($classification->needsConfirmation());
        self::assertSame(NutritionStatus::SEVERELY_UNDERWEIGHT, $classification->wfaStatus);
        self::assertSame(NutritionStatus::TALL, $classification->hfaStatus);
        self::assertSame(NutritionStatus::SEVERELY_WASTED, $classification->wfhStatus);
        self::assertSame(NutritionStatus::OBESE, $classification->bmiStatus);
        self::assertSame(-6.001, $result->wfaZ);
    }

    public function test_AC14_abnormal_nutrition_status_alone_does_not_require_implausibility_confirmation(): void
    {
        $classification = Classifier::classify(self::growthResult(['wfa_z' => -4, 'hfa_z' => -4, 'wfh_z' => 4, 'bmi_z' => 4]));
        self::assertSame(NutritionStatus::SEVERELY_UNDERWEIGHT, $classification->wfaStatus);
        self::assertSame(NutritionStatus::SEVERELY_STUNTED, $classification->hfaStatus);
        self::assertSame(NutritionStatus::OBESE, $classification->wfhStatus);
        self::assertFalse($classification->needsConfirmation());
    }

    public function test_AC06_same_BMI_z_score_has_reference_specific_labels_and_high_WFA_is_not_overweight(): void
    {
        $scores = ['wfa_z' => 2.1, 'hfa_z' => 0, 'bmi_z' => 1.5];
        $young = Classifier::classify(self::growthResult($scores));
        $older = Classifier::classify(self::growthResult($scores, 'WHO2007'));
        self::assertSame(NutritionStatus::NORMAL, $young->bmiStatus);
        self::assertSame(NutritionStatus::OVERWEIGHT, $older->bmiStatus);
        self::assertSame(NutritionStatus::HIGH_WEIGHT_FOR_AGE, $young->wfaStatus);
        self::assertSame($young->wfaStatus, $older->wfaStatus);
        self::assertSame($young->hfaStatus, $older->hfaStatus);
    }

    public static function invalidClassifierScores(): iterable
    {
        foreach (['weightForAge', 'heightForAge', 'weightForHeight', 'WHO2006', 'WHO2007'] as $classifier) {
            foreach ([NAN, INF, -INF] as $value) {
                yield [$classifier, $value];
            }
        }
    }

    #[DataProvider('invalidClassifierScores')]
    public function test_AC06_nonfinite_scores_are_rejected_instead_of_receiving_a_status(string $classifier, float $z): void
    {
        $this->expectException(InvalidArgumentException::class);
        if (str_starts_with($classifier, 'WHO')) {
            Classifier::bmiForAge($z, $classifier);
        } else {
            Classifier::$classifier($z);
        }
    }

    public static function invalidFlagScores(): iterable
    {
        foreach (['wfa_z', 'hfa_z', 'wfh_z', 'bmi_z'] as $indicator) {
            foreach ([NAN, INF, -INF] as $value) {
                yield [$indicator, $value];
            }
        }
    }

    #[DataProvider('invalidFlagScores')]
    public function test_AC14_nonfinite_scores_cannot_bypass_the_confirmation_check(string $indicator, float $z): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImplausibleValues::indicators(self::growthResult([$indicator => $z]));
    }

    public function test_AC06_unknown_reference_is_rejected_even_when_BMI_score_is_unavailable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Classifier::bmiForAge(null, 'UNKNOWN');
    }

    public function test_AC06_AC14_official_numeric_fixtures_receive_expected_labels_and_flags(): void
    {
        $cases = json_decode(file_get_contents(dirname(__DIR__, 2) . '/Fixtures/who-anthro-growth.json'), true, 512, JSON_THROW_ON_ERROR);
        $expected = [
            'FAKE-WHO-19' => ['Severely underweight', 'Normal', 'Severely wasted', 'Severely wasted', ['wfa_z', 'wfh_z', 'bmi_z']],
            'FAKE-WHO-20' => ['High weight-for-age', 'Normal', 'Obese', 'Obese', ['wfa_z', 'wfh_z', 'bmi_z']],
            'FAKE-WHO-21' => ['Severely underweight', 'Normal', null, 'Severe thinness', ['bmi_z']],
            'FAKE-WHO-22' => ['High weight-for-age', 'Normal', null, 'Obese', ['wfa_z', 'bmi_z']],
            'FAKE-WHO-23' => ['Normal', 'Severely stunted', null, 'Obese', ['hfa_z', 'bmi_z']],
            'FAKE-WHO-24' => ['Normal', 'Tall', null, 'Severely wasted', ['hfa_z', 'bmi_z']],
        ];
        $checked = 0;
        foreach ($cases as $case) {
            if (!isset($expected[$case['id']])) {
                continue;
            }
            $classification = Classifier::classify(self::growthResult($case, $case['reference']));
            self::assertSame($expected[$case['id']], [
                $classification->wfaStatus?->value, $classification->hfaStatus?->value,
                $classification->wfhStatus?->value, $classification->bmiStatus?->value,
                $classification->implausibleIndicators,
            ], $case['id']);
            self::assertTrue($classification->needsConfirmation());
            ++$checked;
        }
        self::assertSame(6, $checked);
    }
}
