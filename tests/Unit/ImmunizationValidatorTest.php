<?php

declare(strict_types=1);

use Pediacare\Validation\ImmunizationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImmunizationValidatorTest extends TestCase
{
    private static function input(): array
    {
        return ['schedule_dose_id' => '11', 'date_given' => '2026-01-01', 'given_elsewhere' => '0', 'place_given' => 'FAKE Center'];
    }

    #[DataProvider('invalidFields')]
    public function test_AC11_invalid_health_record_fields_are_rejected(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ImmunizationValidator())->validate(array_replace(self::input(), $changes), new DateTimeImmutable('2026-01-01'));
    }

    public static function invalidFields(): array
    {
        $cases = [];
        foreach (['0', '-1', '1 OR 1=1', '4294967296', []] as $value) {
            $cases[] = [['schedule_dose_id' => $value]];
        }
        foreach (['2026-02-30', '2026-01-02', '2026-1-1', '0000-01-01', "2026-01-01\0", []] as $value) {
            $cases[] = [['date_given' => $value]];
        }
        foreach (['lot_number' => 100, 'site' => 150, 'place_given' => 255] as $field => $limit) {
            foreach ([[], "FAKE\0Text", "\xff", str_repeat('ñ', $limit + 1)] as $value) {
                $cases[] = [[$field => $value]];
            }
        }
        return [...$cases, [['given_elsewhere' => '2']], [['given_elsewhere' => []]], [['place_given' => ' ']]];
    }

    public function test_AC11_optional_fields_normalize_to_null_and_ignore_injected_identity(): void
    {
        $data = (new ImmunizationValidator())->validate(self::input() + ['lot_number' => ' ', 'site' => 'FAKE Site', 'given_by' => '999'], new DateTimeImmutable('2026-01-01'));
        self::assertNull($data['lot_number']);
        self::assertSame('FAKE Site', $data['site']);
        self::assertSame(11, $data['schedule_dose_id']);
        self::assertSame(0, $data['given_elsewhere']);
        self::assertArrayNotHasKey('given_by', $data);
    }
}
