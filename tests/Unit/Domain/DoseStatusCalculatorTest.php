<?php

declare(strict_types=1);

use Pediacare\Domain\Enums\DoseStatus;
use Pediacare\Domain\Immunization\DoseStatusCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DoseStatusCalculatorTest extends TestCase
{
    private static function dose(array $changes = []): array
    {
        return array_replace(['id' => 11, 'vaccine_id' => 7, 'dose_number' => 1,
            'recommended_age_days' => 42, 'recommended_age_months' => null, 'grace_days' => 7,
            'min_interval_days' => 0, 'min_interval_months' => null, 'counts_toward_fic' => 1, 'sort_order' => 1], $changes);
    }

    private static function second(array $changes = []): array
    {
        return self::dose(array_replace(['id' => 12, 'dose_number' => 2, 'recommended_age_days' => 70,
            'min_interval_days' => 28, 'sort_order' => 2], $changes));
    }

    #[DataProvider('boundaries')]
    public function test_AC07_age_and_grace_boundaries_are_inclusive(string $today, int $grace, DoseStatus $expected): void
    {
        $result = (new DoseStatusCalculator())->calculate(new DateTimeImmutable('2026-01-01'),
            [self::dose(['grace_days' => $grace])], [], new DateTimeImmutable($today));
        self::assertSame([11 => $expected], $result);
    }

    public static function boundaries(): array
    {
        return [['2026-02-11', 7, DoseStatus::UPCOMING], ['2026-02-12', 7, DoseStatus::DUE],
            ['2026-02-19', 7, DoseStatus::DUE], ['2026-02-20', 7, DoseStatus::OVERDUE],
            ['2026-02-11', 0, DoseStatus::UPCOMING], ['2026-02-12', 0, DoseStatus::DUE],
            ['2026-02-13', 0, DoseStatus::OVERDUE]];
    }

    public function test_AC07_birth_dose_and_recorded_doses_use_the_configured_identity(): void
    {
        $calculator = new DoseStatusCalculator();
        $dose = self::dose(['recommended_age_days' => 0, 'grace_days' => 0]);
        self::assertSame([11 => DoseStatus::DUE], $calculator->calculate(new DateTimeImmutable('2026-01-01'),
            [$dose], [], new DateTimeImmutable('2026-01-01')));
        self::assertSame([11 => DoseStatus::OVERDUE], $calculator->calculate(new DateTimeImmutable('2026-01-01'),
            [$dose], [], new DateTimeImmutable('2026-01-02')));
        $given = [['schedule_dose_id' => 11, 'date_given' => '2026-01-01', 'given_elsewhere' => 1]];
        self::assertSame([11 => DoseStatus::GIVEN], $calculator->calculate(new DateTimeImmutable('2026-01-01'),
            [$dose], $given, new DateTimeImmutable('2026-06-01')));
        // Recorded doses remain GIVEN after an Admin raises their recommended age.
        self::assertSame([11 => DoseStatus::GIVEN], $calculator->calculate(new DateTimeImmutable('2026-01-01'),
            [self::dose()], $given, new DateTimeImmutable('2026-01-02')));
        self::assertSame([11 => DoseStatus::OVERDUE], $calculator->calculate(new DateTimeImmutable('2026-01-01'),
            [$dose], [['schedule_dose_id' => 999, 'date_given' => '2026-01-01']], new DateTimeImmutable('2026-06-01')));
    }

    #[DataProvider('calendarMonths')]
    public function test_AC07_calendar_months_clamp_without_approximate_days(string $birth, int $months, string $expected): void
    {
        $calculator = new DoseStatusCalculator();
        $doses = [self::dose(['recommended_age_days' => null, 'recommended_age_months' => $months, 'grace_days' => 0])];
        $due = new DateTimeImmutable($expected);
        self::assertSame($expected, $calculator->earliestEligibleDates(new DateTimeImmutable($birth), $doses, [])[11]->format('Y-m-d'));
        self::assertSame([11 => DoseStatus::UPCOMING], $calculator->calculate(new DateTimeImmutable($birth), $doses, [], $due->modify('-1 day')));
        self::assertSame([11 => DoseStatus::DUE], $calculator->calculate(new DateTimeImmutable($birth), $doses, [], $due));
        self::assertSame([11 => DoseStatus::OVERDUE], $calculator->calculate(new DateTimeImmutable($birth), $doses, [], $due->modify('+1 day')));
    }

    public static function calendarMonths(): array
    {
        return [['2026-01-31', 1, '2026-02-28'], ['2024-01-31', 1, '2024-02-29'],
            ['2024-02-29', 12, '2025-02-28'], ['2026-05-31', 1, '2026-06-30'],
            ['2026-01-31', 2, '2026-03-31'], ['2026-03-31', 9, '2026-12-31'],
            ['2026-04-30', 12, '2027-04-30']];
    }

    public function test_AC07_missing_previous_dose_blocks_eligibility_but_preserves_overdue_status(): void
    {
        $calculator = new DoseStatusCalculator();
        $birth = new DateTimeImmutable('2026-01-01');
        $doses = [self::second(), self::dose()];
        $dates = $calculator->earliestEligibleDates($birth, $doses, []);
        self::assertNull($dates[12]);
        self::assertSame('2026-02-12', $dates[11]->format('Y-m-d'));
        self::assertSame([12 => DoseStatus::OVERDUE, 11 => DoseStatus::OVERDUE],
            $calculator->calculate($birth, $doses, [], new DateTimeImmutable('2026-06-01')));
        self::assertNull($calculator->earliestEligibleDates($birth, [self::second()], [])[12]);
    }

    public function test_AC07_late_previous_dose_delays_eligibility_without_restarting_grace(): void
    {
        $calculator = new DoseStatusCalculator();
        $birth = new DateTimeImmutable('2026-01-01');
        $doses = [self::dose(), self::second()];
        $given = [['schedule_dose_id' => 11, 'date_given' => '2026-03-01']];
        $earliest = $calculator->earliestEligibleDates($birth, $doses, $given)[12];
        self::assertSame('2026-03-29', $earliest->format('Y-m-d'));
        self::assertSame(DoseStatus::OVERDUE, $calculator->calculate($birth, $doses, $given, new DateTimeImmutable('2026-03-28'))[12]);
        self::assertSame(DoseStatus::OVERDUE, $calculator->calculate($birth, $doses, $given, $earliest)[12]);
        self::assertSame(DoseStatus::GIVEN, $calculator->calculate($birth, $doses,
            [...$given, ['schedule_dose_id' => 12, 'date_given' => '2026-03-29']], $earliest)[12]);
    }

    public function test_AC07_recommended_age_remains_the_lower_bound_when_interval_ends_earlier(): void
    {
        $calculator = new DoseStatusCalculator();
        $doses = [self::dose(), self::second(['recommended_age_days' => 98])];
        $given = [['schedule_dose_id' => 11, 'date_given' => '2026-02-12']];
        self::assertSame('2026-04-09', $calculator->earliestEligibleDates(new DateTimeImmutable('2026-01-01'), $doses, $given)[12]->format('Y-m-d'));
    }

    #[DataProvider('monthIntervals')]
    public function test_AC07_intervals_keep_calendar_months_separate_from_days(string $previous, ?int $days, ?int $months, string $expected): void
    {
        $calculator = new DoseStatusCalculator();
        $doses = [self::dose(['recommended_age_days' => 0]), self::second(['recommended_age_days' => 0,
            'min_interval_days' => $days, 'min_interval_months' => $months])];
        self::assertSame($expected, $calculator->earliestEligibleDates(new DateTimeImmutable('2024-01-01'),
            $doses, [['schedule_dose_id' => 11, 'date_given' => $previous]])[12]->format('Y-m-d'));
    }

    public static function monthIntervals(): array
    {
        return [['2026-01-31', null, 1, '2026-02-28'], ['2026-01-31', 28, null, '2026-02-28'],
            ['2026-03-31', null, 1, '2026-04-30'], ['2026-03-31', 28, null, '2026-04-28'],
            ['2026-10-31', null, 4, '2027-02-28'], ['2024-10-31', null, 4, '2025-02-28'],
            ['2024-01-31', null, 1, '2024-02-29'], ['2024-02-29', null, 0, '2024-02-29']];
    }

    public function test_AC07_series_use_vaccine_and_dose_numbers_instead_of_array_or_display_order(): void
    {
        $calculator = new DoseStatusCalculator();
        $doses = [self::second(['sort_order' => 0]), self::dose(['id' => 21, 'vaccine_id' => 8]), self::dose(['sort_order' => 100])];
        $given = [['schedule_dose_id' => 21, 'date_given' => '2026-02-12']];
        $birth = new DateTimeImmutable('2026-01-01');
        self::assertNull($calculator->earliestEligibleDates($birth, $doses, $given)[12]);
        $given[] = ['schedule_dose_id' => 11, 'date_given' => '2026-03-01'];
        self::assertSame('2026-03-29', $calculator->earliestEligibleDates($birth, $doses, $given)[12]->format('Y-m-d'));
    }

    public function test_AC07_date_only_rules_ignore_time_and_timezone_without_mutating_inputs(): void
    {
        $calculator = new DoseStatusCalculator();
        $birth = new DateTimeImmutable('2026-01-01T23:59:00-12:00');
        $today = new DateTimeImmutable('2026-02-12T00:01:00+14:00');
        self::assertSame([11 => DoseStatus::DUE], $calculator->calculate($birth, [self::dose()], [], $today));
        self::assertSame('2026-01-01T23:59:00-12:00', $birth->format('c'));
        self::assertSame('2026-02-12T00:01:00+14:00', $today->format('c'));
        self::assertSame([], $calculator->calculate($birth, [], [], $today));
        self::assertSame([], $calculator->earliestEligibleDates($birth, [], []));
    }

    #[DataProvider('invalidDoses')]
    public function test_AC07_invalid_schedule_data_is_rejected(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DoseStatusCalculator())->calculate(new DateTimeImmutable('2026-01-01'),
            [self::dose($changes)], [], new DateTimeImmutable('2026-06-01'));
    }

    public static function invalidDoses(): array
    {
        return [[['id' => 0]], [['vaccine_id' => 0]], [['dose_number' => 0]], [['id' => '11']],
            [['grace_days' => -1]], [['grace_days' => '0']], [['recommended_age_days' => null]],
            [['recommended_age_months' => 1]], [['min_interval_days' => null]], [['min_interval_months' => 1]],
            [['recommended_age_days' => -1]], [['recommended_age_days' => 0.5]], [['min_interval_days' => -1]],
            [['recommended_age_days' => 4294967296]], [['min_interval_days' => []]]];
    }

    #[DataProvider('invalidRecords')]
    public function test_AC07_invalid_dates_and_duplicate_immunizations_are_rejected(array $records): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DoseStatusCalculator())->calculate(new DateTimeImmutable('2026-01-01'), [self::dose()], $records,
            new DateTimeImmutable('2026-06-01'));
    }

    public static function invalidRecords(): array
    {
        $cases = [];
        foreach (['2026-02-30', '2026-02-29', '2025-12-31', '2026-1-1', "2026-01-01\0", 'invalid', []] as $date) {
            $cases[] = [[['schedule_dose_id' => 11, 'date_given' => $date]]];
        }
        return [...$cases, [[['schedule_dose_id' => '11', 'date_given' => '2026-01-01']]],
            [[['schedule_dose_id' => 11, 'date_given' => '2026-01-01'], ['schedule_dose_id' => 11, 'date_given' => '2026-01-02']]]];
    }

    public function test_AC07_ambiguous_schedule_identities_are_rejected(): void
    {
        $calculator = new DoseStatusCalculator();
        foreach ([[self::dose(), self::dose()], [self::dose(), self::dose(['id' => 99])]] as $doses) {
            try {
                $calculator->earliestEligibleDates(new DateTimeImmutable('2026-01-01'), $doses, []);
                self::fail('Expected duplicate dose rejection.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Duplicate schedule dose identity.', $error->getMessage());
            }
        }
    }

    public function test_AC07_future_birth_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DoseStatusCalculator())->calculate(new DateTimeImmutable('2026-01-02'), [self::dose()], [], new DateTimeImmutable('2026-01-01'));
    }

    public function test_AC07_schedule_date_overflow_is_rejected_for_both_units(): void
    {
        foreach ([['recommended_age_days' => 4294967295], ['recommended_age_days' => null, 'recommended_age_months' => 4294967295]] as $changes) {
            try {
                (new DoseStatusCalculator())->earliestEligibleDates(new DateTimeImmutable('2026-01-01'), [self::dose($changes)], []);
                self::fail('Expected calendar overflow rejection.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Schedule date exceeds the supported calendar.', $error->getMessage());
            }
        }
    }
}
