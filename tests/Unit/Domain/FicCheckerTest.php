<?php

declare(strict_types=1);

use Pediacare\Domain\Enums\DoseStatus;
use Pediacare\Domain\Immunization\DoseStatusCalculator;
use Pediacare\Domain\Immunization\FicChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FicCheckerTest extends TestCase
{
    public function test_AC08_only_configured_fic_doses_are_required(): void
    {
        $checker = new FicChecker();
        $doses = [['id' => 1, 'counts_toward_fic' => 1], ['id' => 2, 'counts_toward_fic' => 1],
            ['id' => 3, 'counts_toward_fic' => 0]];
        self::assertTrue($checker->isFullyImmunized($doses, [1 => DoseStatus::GIVEN, 2 => DoseStatus::GIVEN]));
        self::assertTrue($checker->isFullyImmunized($doses, [1 => DoseStatus::GIVEN, 2 => DoseStatus::GIVEN, 3 => DoseStatus::OVERDUE]));
        foreach ([DoseStatus::DUE, DoseStatus::UPCOMING, DoseStatus::OVERDUE, null] as $status) {
            self::assertFalse($checker->isFullyImmunized($doses, [1 => DoseStatus::GIVEN, 2 => $status]));
        }
        self::assertFalse($checker->isFullyImmunized($doses, [1 => DoseStatus::GIVEN, 999 => DoseStatus::GIVEN]));
        self::assertFalse($checker->isFullyImmunized($doses, []));
    }

    public function test_AC08_empty_configuration_never_reports_fic(): void
    {
        $checker = new FicChecker();
        self::assertFalse($checker->isFullyImmunized([], []));
        self::assertFalse($checker->isFullyImmunized([], [1 => DoseStatus::GIVEN]));
        self::assertFalse($checker->isFullyImmunized([['id' => 1, 'counts_toward_fic' => 0]], [1 => DoseStatus::GIVEN]));
    }

    public function test_AC08_admin_configuration_changes_recompute_fic_membership(): void
    {
        $checker = new FicChecker();
        $statuses = [1 => DoseStatus::GIVEN, 2 => DoseStatus::OVERDUE];
        self::assertTrue($checker->isFullyImmunized([['id' => 1, 'counts_toward_fic' => 1], ['id' => 2, 'counts_toward_fic' => 0]], $statuses));
        self::assertFalse($checker->isFullyImmunized([['id' => 1, 'counts_toward_fic' => 1], ['id' => 2, 'counts_toward_fic' => 1]], $statuses));
    }

    public function test_AC08_approved_draft_requires_all_nine_flagged_doses_including_elsewhere_records(): void
    {
        $seed = require dirname(__DIR__, 3) . '/database/seeds/vaccine-schedule.php';
        $doses = [];
        $records = [];
        $ficIds = [];
        foreach ($seed as $vaccineId => $vaccine) {
            foreach ($vaccine['doses'] as $number => [$ageDays, $ageMonths, $intervalDays, $intervalMonths, $fic]) {
                $id = count($doses) + 1;
                $doses[] = ['id' => $id, 'vaccine_id' => $vaccineId + 1, 'dose_number' => $number + 1,
                    'recommended_age_days' => $ageDays, 'recommended_age_months' => $ageMonths, 'grace_days' => 0,
                    'min_interval_days' => $intervalDays, 'min_interval_months' => $intervalMonths, 'counts_toward_fic' => $fic];
                if ($fic === 1) {
                    $ficIds[] = $id;
                    $records[] = ['schedule_dose_id' => $id, 'date_given' => '2026-01-01', 'given_elsewhere' => 1];
                }
            }
        }
        self::assertCount(9, $ficIds);
        $calculator = new DoseStatusCalculator();
        $checker = new FicChecker();
        $birth = new DateTimeImmutable('2024-01-01');
        $today = new DateTimeImmutable('2026-01-01');
        self::assertTrue($checker->isFullyImmunized($doses, $calculator->calculate($birth, $doses, $records, $today)));
        foreach ($ficIds as $missing) {
            $partial = array_values(array_filter($records, static fn (array $row): bool => $row['schedule_dose_id'] !== $missing));
            self::assertFalse($checker->isFullyImmunized($doses, $calculator->calculate($birth, $doses, $partial, $today)));
        }
    }

    #[DataProvider('invalidConfiguration')]
    public function test_AC08_invalid_configuration_is_rejected(array $doses): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FicChecker())->isFullyImmunized($doses, []);
    }

    public static function invalidConfiguration(): array
    {
        return [[[['id' => 0, 'counts_toward_fic' => 1]]], [[['id' => '1', 'counts_toward_fic' => 1]]],
            [[['id' => 1, 'counts_toward_fic' => 2]]], [[['id' => 1, 'counts_toward_fic' => '1']]],
            [[['id' => 1]]], [[['id' => 1, 'counts_toward_fic' => 0], ['id' => 1, 'counts_toward_fic' => 1]]]];
    }
}
