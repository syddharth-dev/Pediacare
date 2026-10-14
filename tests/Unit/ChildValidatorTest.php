<?php

declare(strict_types=1);

use Pediacare\Validation\ChildValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChildValidatorTest extends TestCase
{
    public function test_AC11_editing_an_older_child_keeps_validation_without_new_registration_age_limit(): void
    {
        $input = require dirname(__DIR__) . '/Fixtures/child.php';
        $input['date_of_birth'] = '2020-01-01';
        $validator = new ChildValidator();
        self::assertSame('2020-01-01', $validator->validate($input, new DateTimeImmutable('2026-10-10'), false)['date_of_birth']);
        foreach (['2026-10-11', '2020-02-30', '0000-01-01'] as $date) {
            try {
                $validator->validate(array_replace($input, ['date_of_birth' => $date]), new DateTimeImmutable('2026-10-10'), false);
                self::fail('Invalid edit birth date was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('birth', $error->getMessage());
            }
        }
    }

    public function test_AC11_archive_and_restore_reasons_are_validated_and_require_confirmation(): void
    {
        $validator = new ChildValidator();
        self::assertSame('FAKE Reason', $validator->reason(['reason' => ' FAKE Reason ', 'confirm' => '1']));
        foreach ([[], ['reason' => 'FAKE Reason'], ['reason' => ['FAKE'], 'confirm' => '1'],
            ['reason' => "FAKE\0", 'confirm' => '1'], ['reason' => "\xff", 'confirm' => '1'],
            ['reason' => ' ', 'confirm' => '1'], ['reason' => str_repeat('界', 22000), 'confirm' => '1'],
            ['reason' => "\u{2000}\u{00A0}", 'confirm' => '1'],
            ['reason' => 'FAKE Reason', 'confirm' => ['1']]] as $input) {
            try {
                $validator->reason($input);
                self::fail('Invalid reason or missing confirmation was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }

    public function test_AC04_validates_demographics_normalizes_name_and_optional_values(): void
    {
        $input = require dirname(__DIR__) . '/Fixtures/child.php';
        $input['first_name'] = ' FAKE   Child ';
        $input['birth_weight_kg'] = '3.5';
        $input['birth_length_cm'] = '50';
        $input['record_no'] = 'FORGED';
        $input['created_by'] = '999';
        $data = (new ChildValidator())->validate($input);
        self::assertSame('FAKE Child', $data['first_name']);
        self::assertNull($data['father_name']);
        self::assertNull($data['middle_name']);
        self::assertNull($data['notes']);
        self::assertSame(7, $data['purok_id']);
        self::assertSame('3.50', $data['birth_weight_kg']);
        self::assertSame('50.0', $data['birth_length_cm']);
        self::assertArrayNotHasKey('record_no', $data);
        self::assertArrayNotHasKey('created_by', $data);
    }

    #[DataProvider('invalidInput')]
    public function test_AC04_invalid_registration_input_is_rejected(array $changes): void
    {
        $input = require dirname(__DIR__) . '/Fixtures/child.php';
        $this->expectException(InvalidArgumentException::class);
        (new ChildValidator())->validate(array_replace($input, $changes));
    }

    public static function invalidInput(): array
    {
        return [
            [['first_name' => '']], [['first_name' => []]], [['last_name' => str_repeat('ñ', 256)]],
            [['first_name' => "\u{2000}\u{00A0}"]], [['address' => "\u{2000}\u{00A0}"]],
            [['mother_name' => "FAKE\0Mother"]], [['guardian_name' => "\xff"]], [['contact_no' => '']],
            [['sex' => 'X']], [['sex' => []]], [['date_of_birth' => '2026-02-30']],
            [['date_of_birth' => "2025-01-01\0"]], [['date_of_birth' => '1900-01-01']],
            [['date_of_birth' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d')]],
            [['purok_id' => '0']], [['purok_id' => '4294967296']], [['purok_id' => []]],
            [['birth_weight_kg' => '0']], [['birth_weight_kg' => '-1']], [['birth_weight_kg' => '100']],
            [['birth_weight_kg' => '3.555']], [['birth_weight_kg' => '1e1']], [['birth_weight_kg' => []]],
            [['birth_length_cm' => '1000']], [['birth_length_cm' => '50.55']],
            [['notes' => str_repeat('ñ', 32768)]],
        ];
    }

    #[DataProvider('birthDates')]
    public function test_AC04_registration_age_boundary_is_under_five_years(string $date, bool $valid): void
    {
        $input = require dirname(__DIR__) . '/Fixtures/child.php';
        $input['date_of_birth'] = $date;
        if (!$valid) {
            $this->expectException(InvalidArgumentException::class);
        }
        $result = (new ChildValidator())->validate($input, new DateTimeImmutable('2026-10-10'));
        if ($valid) {
            self::assertSame($date, $result['date_of_birth']);
        }
    }

    public static function birthDates(): array
    {
        return [['2026-10-10', true], ['2021-10-11', true], ['2021-10-10', false], ['2026-10-11', false],
            ['2024-02-29', true], ['2023-02-29', false]];
    }
}
