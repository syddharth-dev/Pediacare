<?php

declare(strict_types=1);

use Pediacare\Validation\VaccineScheduleValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VaccineScheduleValidatorTest extends TestCase
{
    private static function dose(): array
    {
        return ['dose_number' => '1', 'age_value' => '0', 'age_unit' => 'days', 'grace_days' => '0',
            'interval_value' => '0', 'interval_unit' => 'days', 'counts_toward_fic' => '0', 'sort_order' => '0'];
    }

    #[DataProvider('invalidDoseFields')]
    public function test_AC07_AC08_rejects_invalid_schedule_fields(string $field, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new VaccineScheduleValidator())->dose(array_replace(self::dose(), [$field => $value]));
    }

    public static function invalidDoseFields(): array
    {
        $cases = [];
        foreach (['dose_number', 'age_value', 'interval_value', 'grace_days', 'sort_order'] as $field) {
            foreach (['-1', '1.5', '4294967296', '1e2', '1 OR 1=1', '', [], null, 1] as $value) {
                $cases[] = [$field, $value];
            }
        }
        return [...$cases, ['dose_number', '0'], ['age_unit', 'weeks'], ['interval_unit', []],
            ['counts_toward_fic', '2'], ['counts_toward_fic', true]];
    }

    #[DataProvider('invalidVaccines')]
    public function test_AC02_rejects_invalid_vaccine_details(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new VaccineScheduleValidator())->vaccine(array_replace(['name' => 'FAKE', 'code' => 'FAKE', 'active' => '1'], $changes), true);
    }

    public static function invalidVaccines(): array
    {
        return [[['name' => []]], [['name' => ' ']], [['name' => str_repeat('ñ', 151)]], [['name' => "FAKE\0Name"]],
            [['name' => "\xff"]], [['code' => 'fake']], [['code' => 'FAKE CODE']], [['code' => str_repeat('A', 33)]],
            [['code' => []]], [['active' => '2']], [['active' => 1]]];
    }

    public function test_AC07_AC08_keeps_exact_unit_pairs_and_configured_completion_flag(): void
    {
        $validator = new VaccineScheduleValidator();
        $days = $validator->dose(array_replace(self::dose(), ['age_value' => '42', 'interval_value' => '28', 'counts_toward_fic' => '1']));
        self::assertSame(42, $days['recommended_age_days']);
        self::assertNull($days['recommended_age_months']);
        self::assertSame(28, $days['min_interval_days']);
        self::assertNull($days['min_interval_months']);
        self::assertSame(1, $days['counts_toward_fic']);
        $months = $validator->dose(array_replace(self::dose(), ['age_value' => '9', 'age_unit' => 'months',
            'interval_value' => '4', 'interval_unit' => 'months']));
        self::assertNull($months['recommended_age_days']);
        self::assertSame(9, $months['recommended_age_months']);
        self::assertNull($months['min_interval_days']);
        self::assertSame(4, $months['min_interval_months']);
        self::assertSame(0, $months['counts_toward_fic']);
        self::assertSame(['name' => 'FAKE', 'active' => 0], $validator->vaccine(['name' => ' FAKE ', 'active' => '0', 'code' => 'INJECTED'], false));
    }
}
