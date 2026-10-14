<?php

declare(strict_types=1);

use Pediacare\Domain\Enums\MeasurementPosition;
use Pediacare\Domain\Growth\AgeInMonths;
use Pediacare\Domain\Growth\Bmi;
use Pediacare\Domain\Growth\LengthHeight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrowthBasicsTest extends TestCase
{
    #[DataProvider('ages')]
    public function test_AC06_age_retains_completed_calendar_months_and_exact_days(
        string $birth,
        string $measurement,
        int $months,
        int $days
    ): void {
        $age = AgeInMonths::between(new DateTimeImmutable($birth), new DateTimeImmutable($measurement));
        self::assertSame($months, $age->months);
        self::assertSame($days, $age->days);
    }

    public static function ages(): array
    {
        return [
            'birth date' => ['2024-01-01', '2024-01-01', 0, 0],
            'day before monthly anniversary' => ['2024-01-15', '2024-02-14', 0, 30],
            'monthly anniversary' => ['2024-01-15', '2024-02-15', 1, 31],
            'end of short month' => ['2024-01-31', '2024-02-29', 0, 29],
            'after short month' => ['2024-01-31', '2024-03-01', 1, 30],
            'two months after month end' => ['2024-01-31', '2024-03-31', 2, 60],
            'leap birth before anniversary' => ['2024-02-29', '2025-02-28', 11, 365],
            'leap birth after anniversary' => ['2024-02-29', '2025-03-01', 12, 366],
            'nonleap second birthday' => ['2021-01-01', '2023-01-01', 24, 730],
            'leap second birthday' => ['2023-01-01', '2025-01-01', 24, 731],
            'five years before anniversary' => ['2021-01-15', '2026-01-14', 59, 1825],
            'five years anniversary' => ['2021-01-15', '2026-01-15', 60, 1826],
            'month 61' => ['2021-01-15', '2026-02-15', 61, 1857],
            'ignore birth time' => ['2024-01-01T23:59:59+08:00', '2024-01-01T00:00:00+08:00', 0, 0],
            'preserve local dates across offsets' => ['2024-01-01T23:59:59-12:00', '2024-01-02T00:00:00+14:00', 0, 1],
            'DST dates' => ['2024-03-09T23:00:00 America/New_York', '2024-03-11T01:00:00 America/New_York', 0, 2],
        ];
    }

    public function test_AC06_measurement_before_birth_is_rejected_without_exposing_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Measurement date must be on or after birth date.');
        AgeInMonths::between(new DateTimeImmutable('2024-01-02'), new DateTimeImmutable('2024-01-01'));
    }

    #[DataProvider('bmis')]
    public function test_AC06_bmi_uses_kilograms_and_centimetres_without_early_rounding(
        float $weight,
        float $height,
        float $expected
    ): void {
        self::assertEqualsWithDelta($expected, Bmi::calculate($weight, $height), 0.0000000001);
    }

    public static function bmis(): array
    {
        return [[16.0, 100.0, 16.0], [8.0, 80.0, 12.5], [3.5, 50.0, 14.0],
            [10.0, 75.0, 17.77777777777778], [18.25, 105.5, 16.39675658677927]];
    }

    #[DataProvider('invalidBmis')]
    public function test_AC06_invalid_or_unrepresentable_bmi_inputs_are_rejected(float $weight, float $height): void
    {
        $this->expectException(InvalidArgumentException::class);
        Bmi::calculate($weight, $height);
    }

    public static function invalidBmis(): array
    {
        return [[0.0, 80.0], [-1.0, 80.0], [NAN, 80.0], [INF, 80.0], [-INF, 80.0],
            [8.0, 0.0], [8.0, -80.0], [8.0, NAN], [8.0, INF], [8.0, -INF],
            [8.0, 1.0e-200], [8.0, 1.0e200], [1.0e300, 1.0e-100], [1.0e-300, 1.0e100]];
    }

    #[DataProvider('positions')]
    public function test_AC06_position_adjustment_matches_who_730_and_731_day_boundary(
        int $days,
        MeasurementPosition $position,
        float $expected
    ): void {
        $birth = new DateTimeImmutable('2021-01-01');
        $age = AgeInMonths::between($birth, $birth->modify('+' . $days . ' days'));
        self::assertEqualsWithDelta($expected, LengthHeight::adjust(80.123, $position, $age), 0.0000000001);
    }

    public static function positions(): array
    {
        return [[0, MeasurementPosition::LYING, 80.123], [0, MeasurementPosition::STANDING, 80.823],
            [730, MeasurementPosition::LYING, 80.123], [730, MeasurementPosition::STANDING, 80.823],
            [731, MeasurementPosition::LYING, 79.423], [731, MeasurementPosition::STANDING, 80.123]];
    }

    public function test_AC06_bmi_uses_the_adjusted_measurement_and_keeps_the_entered_value(): void
    {
        $age = AgeInMonths::between(new DateTimeImmutable('2021-01-01'), new DateTimeImmutable('2023-01-02'));
        $measured = 80.7;
        $adjusted = LengthHeight::adjust($measured, MeasurementPosition::LYING, $age);
        self::assertEqualsWithDelta(80.0, $adjusted, 0.0000000001);
        self::assertEqualsWithDelta(12.5, Bmi::calculate(8.0, $adjusted), 0.0000000001);
        self::assertSame(80.7, $measured);
        self::assertSame(731, $age->days);
    }

    #[DataProvider('invalidLengths')]
    public function test_AC06_invalid_or_nonpositive_adjusted_lengths_are_rejected(float $length): void
    {
        $age = AgeInMonths::between(new DateTimeImmutable('2021-01-01'), new DateTimeImmutable('2023-01-02'));
        $this->expectException(InvalidArgumentException::class);
        LengthHeight::adjust($length, MeasurementPosition::LYING, $age);
    }

    public static function invalidLengths(): array
    {
        return [[0.0], [-1.0], [NAN], [INF], [-INF], [0.7], [0.6]];
    }
}
