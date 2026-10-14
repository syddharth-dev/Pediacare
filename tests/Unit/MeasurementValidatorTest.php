<?php

declare(strict_types=1);

use Pediacare\Validation\MeasurementValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MeasurementValidatorTest extends TestCase
{
    private static function input(): array
    {
        return ['measured_at' => '2026-01-01', 'weight_kg' => '10.2', 'height_cm' => '80', 'muac_cm' => '', 'position' => 'LYING'];
    }

    public function test_AC06_normalizes_inputs_and_ignores_forged_computed_results_and_actor(): void
    {
        $data = (new MeasurementValidator())->validate(self::input() + ['bmi' => '1', 'wfa_z' => '0', 'measured_by' => '999']);
        self::assertSame(['measured_at' => '2026-01-01', 'weight_kg' => '10.20', 'height_cm' => '80.0', 'muac_cm' => null, 'position' => 'LYING'], $data);
    }

    public static function invalidInputs(): iterable
    {
        foreach (['weight_kg' => ['0', '-1', '1000', '1.234', 'NaN', 'INF', '1e1', ['1']],
            'height_cm' => ['0', '1000', '80.12', '', ['80']],
            'muac_cm' => ['0', '1000', '12.34', ['12']],
            'position' => ['', 'SITTING', ['LYING']],
            'measured_at' => ['2026-02-30', '2026-1-01', '0999-01-01', '2026-01-03', ['2026-01-01']]] as $field => $values) {
            foreach ($values as $value) {
                yield [array_replace(self::input(), [$field => $value])];
            }
        }
    }

    #[DataProvider('invalidInputs')]
    public function test_AC06_invalid_dates_values_positions_and_precision_are_rejected(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MeasurementValidator())->validate($input, new DateTimeImmutable('2026-01-02'));
    }

    public function test_AC06_storage_endpoints_and_optional_MUAC_are_accepted(): void
    {
        $data = (new MeasurementValidator())->validate(array_replace(self::input(), ['weight_kg' => '999.99', 'height_cm' => '999.9', 'muac_cm' => '999.9']));
        self::assertSame('999.99', $data['weight_kg']);
        self::assertSame('999.9', $data['muac_cm']);
    }
}
