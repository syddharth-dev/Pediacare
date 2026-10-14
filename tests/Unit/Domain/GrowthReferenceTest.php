<?php

declare(strict_types=1);

use Pediacare\Domain\Enums\MeasurementPosition;
use Pediacare\Domain\Enums\Sex;
use Pediacare\Domain\Growth\AgeInMonths;
use Pediacare\Domain\Growth\GrowthCalculator;
use Pediacare\Domain\Growth\LmsParameters;
use Pediacare\Domain\Growth\LmsTable;
use Pediacare\Domain\Growth\LmsZScore;
use Pediacare\Domain\Growth\Reference\Who2006Standard;
use Pediacare\Domain\Growth\Reference\Who2007Reference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrowthReferenceTest extends TestCase
{
    private static ?LmsTable $table = null;

    private static function table(): LmsTable
    {
        if (self::$table === null) {
            $rows = [];
            foreach (['who2006.csv', 'who2007.csv'] as $file) {
                $stream = fopen(dirname(__DIR__, 3) . '/database/who/' . $file, 'rb');
                $header = fgetcsv($stream);
                while (($values = fgetcsv($stream)) !== false) {
                    $rows[] = array_combine($header, $values);
                }
                fclose($stream);
            }
            self::$table = new LmsTable($rows);
        }

        return self::$table;
    }

    private static function age(int $days): AgeInMonths
    {
        $birth = new DateTimeImmutable('2000-01-01');

        return AgeInMonths::between($birth, $birth->modify('+' . $days . ' days'));
    }

    private static function calculator(): GrowthCalculator
    {
        return new GrowthCalculator(new Who2006Standard(self::table()), new Who2007Reference(self::table()));
    }

    public static function whoCases(): iterable
    {
        $cases = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/Fixtures/who-anthro-growth.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        foreach ($cases as $case) {
            yield $case['id'] => [$case];
        }
    }

    #[DataProvider('whoCases')]
    public function test_AC06_results_match_official_WHO_Anthro_and_AnthroPlus_calculation_output(array $case): void
    {
        $result = self::calculator()->calculate(
            Sex::from($case['sex']), self::age($case['days']), $case['weight_kg'], $case['height_cm'],
            MeasurementPosition::from($case['position']),
        );
        self::assertSame($case['reference'], $result->reference);
        self::assertEqualsWithDelta($case['adjusted_height_cm'], $result->adjustedHeightCm, 1e-10);
        self::assertEqualsWithDelta($case['bmi'], $result->bmi, 1e-10);
        foreach (['wfa_z' => 'wfaZ', 'hfa_z' => 'hfaZ', 'wfh_z' => 'wfhZ', 'bmi_z' => 'bmiZ'] as $key => $property) {
            if ($case[$key] === null) {
                self::assertNull($result->$property, $key);
            } else {
                self::assertNotNull($result->$property, $key);
                self::assertEqualsWithDelta($case[$key], $result->$property, 0.01, $key);
            }
        }
    }

    public function test_AC06_reference_boundary_uses_WHO_age_while_calendar_age_stays_unchanged(): void
    {
        $age = AgeInMonths::between(new DateTimeImmutable('2021-02-01'), new DateTimeImmutable('2026-03-01'));
        self::assertSame(61, $age->months);
        self::assertSame(1854, $age->days);
        self::assertEqualsWithDelta(60.911704312115, $age->whoMonths(), 1e-10);
        self::assertTrue((new Who2006Standard(self::table()))->supports($age));
        self::assertFalse((new Who2007Reference(self::table()))->supports($age));
        self::assertTrue((new Who2007Reference(self::table()))->supports(self::age(1857)));
    }

    public function test_AC06_interpolation_is_linear_in_each_LMS_parameter_and_never_extrapolates(): void
    {
        $table = new LmsTable([
            ['reference' => 'WHO2007', 'indicator' => 'BFA', 'sex' => 'F', 'age_or_size' => 62, 'l' => -2, 'm' => 20, 's' => 0.2],
            ['reference' => 'WHO2007', 'indicator' => 'BFA', 'sex' => 'F', 'age_or_size' => 61, 'l' => -1, 'm' => 10, 's' => 0.1],
        ]);
        $parameters = $table->at('WHO2007', 'BFA', Sex::FEMALE, 61.25);
        self::assertSame(-1.25, $parameters->l);
        self::assertSame(12.5, $parameters->m);
        self::assertEqualsWithDelta(0.125, $parameters->s, 1e-12);
        self::assertSame(-1.0, $table->at('WHO2007', 'BFA', Sex::FEMALE, 61)->l);
        self::assertNull($table->at('WHO2007', 'BFA', Sex::FEMALE, 60.999));
        self::assertNull($table->at('WHO2007', 'BFA', Sex::FEMALE, 62.001));
    }

    public function test_AC06_zero_lambda_uses_logarithm_and_restricted_tails_keep_the_SD2_SD3_distance(): void
    {
        $parameters = new LmsParameters(0, 10, 0.1);
        self::assertEqualsWithDelta(0, LmsZScore::calculate(10, $parameters, true), 1e-12);
        self::assertEqualsWithDelta(2, LmsZScore::calculate(10 * exp(0.2), $parameters, false), 1e-12);
        foreach ([-1, 1] as $direction) {
            $sd3 = 10 * exp($direction * 0.3);
            $sd2 = 10 * exp($direction * 0.2);
            $value = $sd3 + $direction * abs($sd3 - $sd2);
            self::assertEqualsWithDelta($direction * 4, LmsZScore::calculate($value, $parameters, true), 1e-12);
            self::assertGreaterThan(0.01, abs(LmsZScore::calculate($value, $parameters, false) - $direction * 4));
        }
    }

    public function test_AC06_height_z_score_uses_unrestricted_LMS_outside_three_SD(): void
    {
        $parameters = self::table()->at('WHO2006', 'HFA', Sex::MALE, 365);
        $height = $parameters->m * (1 + $parameters->s * 4);
        $result = self::calculator()->calculate(Sex::MALE, self::age(365), 10, $height, MeasurementPosition::LYING);
        self::assertEqualsWithDelta(4, $result->hfaZ, 1e-10);
    }

    public function test_AC06_unavailable_weight_for_age_is_null_after_120_completed_WHO_months(): void
    {
        $before = self::calculator()->calculate(Sex::FEMALE, self::age(3682), 31, 138, MeasurementPosition::STANDING);
        $after = self::calculator()->calculate(Sex::FEMALE, self::age(3683), 31, 138, MeasurementPosition::STANDING);
        self::assertNotNull($before->wfaZ);
        self::assertNull($after->wfaZ);
        self::assertNotNull($after->bmiZ);
        self::assertNotNull($after->hfaZ);
        self::assertNull($after->wfhZ);
    }

    public function test_AC06_last_supported_WHO_day_is_6970_and_next_day_is_rejected(): void
    {
        self::assertNotNull(self::calculator()->calculate(Sex::MALE, self::age(6970), 60, 170, MeasurementPosition::STANDING)->bmiZ);
        $this->expectException(OutOfRangeException::class);
        self::calculator()->calculate(Sex::MALE, self::age(6971), 60, 170, MeasurementPosition::STANDING);
    }

    public function test_AC06_missing_reference_data_fails_instead_of_returning_uncomputed_scores(): void
    {
        $this->expectException(LogicException::class);
        (new Who2006Standard(new LmsTable([])))->calculate(Sex::MALE, self::age(0), 3.5, 50, MeasurementPosition::LYING);
    }

    public static function invalidValues(): iterable
    {
        foreach ([0, -1, NAN, INF, -INF] as $value) {
            yield [$value];
        }
    }

    #[DataProvider('invalidValues')]
    public function test_AC06_LMS_rejects_nonpositive_or_nonfinite_observations(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        LmsZScore::calculate($value, new LmsParameters(1, 10, 0.1), true);
    }

    public static function invalidParameters(): iterable
    {
        yield [NAN, 10, 0.1];
        yield [INF, 10, 0.1];
        yield [1, 0, 0.1];
        yield [1, -10, 0.1];
        yield [1, INF, 0.1];
        yield [1, 10, 0];
        yield [1, 10, -0.1];
        yield [1, 10, NAN];
    }

    #[DataProvider('invalidParameters')]
    public function test_AC06_LMS_rejects_invalid_parameters(float $l, float $m, float $s): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LmsParameters($l, $m, $s);
    }

    public static function invalidRows(): iterable
    {
        $row = ['reference' => 'WHO2006', 'indicator' => 'WFA', 'sex' => 'M', 'age_or_size' => 0, 'l' => 1, 'm' => 10, 's' => 0.1];
        foreach (['reference' => 'OTHER', 'indicator' => 'OTHER', 'sex' => 'X', 'age_or_size' => -1, 'l' => 'abc', 'm' => INF, 's' => 0] as $key => $value) {
            yield [array_replace($row, [$key => $value])];
        }
    }

    #[DataProvider('invalidRows')]
    public function test_AC06_table_rejects_invalid_reference_rows(array $row): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LmsTable([$row]);
    }

    public function test_AC06_table_rejects_duplicate_coordinates(): void
    {
        $row = ['reference' => 'WHO2006', 'indicator' => 'WFA', 'sex' => 'M', 'age_or_size' => 0, 'l' => 1, 'm' => 10, 's' => 0.1];
        $this->expectException(InvalidArgumentException::class);
        new LmsTable([$row, $row]);
    }

    public function test_AC06_wrong_strategy_age_is_rejected(): void
    {
        $this->expectException(OutOfRangeException::class);
        (new Who2007Reference(self::table()))->calculate(Sex::MALE, self::age(1856), 19, 111, MeasurementPosition::STANDING);
    }
}
