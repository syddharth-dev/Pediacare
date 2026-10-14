<?php

declare(strict_types=1);

use Pediacare\Validation\VisitValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VisitValidatorTest extends TestCase
{
    #[DataProvider('invalidFields')]
    public function test_AC11_invalid_visit_and_supplement_fields_are_rejected(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new VisitValidator())->validate(array_replace(['visit_date' => '2026-01-01',
            'reason' => 'FAKE visit', 'referred' => '0'], $changes), new DateTimeImmutable('2026-01-01'));
    }

    public static function invalidFields(): array
    {
        $cases = [];
        foreach (['2026-02-30', '2026-01-02', '2026-1-1', '0000-01-01', "2026-01-01\0", []] as $value) {
            $cases[] = [['visit_date' => $value]];
        }
        foreach (['reason', 'findings', 'actions', 'notes', 'referred_to'] as $field) {
            foreach ([[], "FAKE\0Text", "\xff"] as $value) {
                $cases[] = [[$field => $value]];
            }
        }
        return [...$cases, [['reason' => ' ']], [['reason' => str_repeat('x', 256)]],
            [['notes' => str_repeat('ñ', 32768)]], [['referred' => '2']], [['referred' => []]],
            [['referred' => '1']], [['referred_to' => 'FAKE Facility']], [['supplements' => 'bad']],
            [['supplements' => ['UNKNOWN' => []]]], [['supplements' => ['IRON' => 'bad']]],
            [['supplements' => ['IRON' => ['dose' => []]]]],
            [['supplements' => ['IRON' => ['date_given' => '2026-01-01']]]],
            [['supplements' => ['IRON' => ['dose' => 'FAKE 1 mg']]]],
            [['supplements' => ['IRON' => ['dose' => 'FAKE 1 mg', 'date_given' => '2026-01-02']]]],
            [['supplements' => ['IRON' => ['dose' => str_repeat('x', 101), 'date_given' => '2026-01-01']]]],
            [['supplements' => ['IRON' => ['date_given' => []]]]]];
    }

    public function test_AC11_optional_services_can_be_blank_and_all_four_types_retain_entered_doses(): void
    {
        $input = ['visit_date' => '2026-01-01', 'reason' => ' FAKE visit ', 'referred' => '0',
            'child_id' => '999', 'attended_by' => '999', 'notes' => ' '];
        $validator = new VisitValidator();
        $data = $validator->validate($input, new DateTimeImmutable('2026-01-01'));
        self::assertSame('FAKE visit', $data['visit']['reason']);
        self::assertNull($data['visit']['notes']);
        self::assertNull($data['visit']['referred_to']);
        self::assertArrayNotHasKey('attended_by', $data['visit']);
        self::assertSame([], $data['supplements']);
        foreach (VisitValidator::SUPPLEMENTS as $type => $label) {
            $input['supplements'][$type] = ['dose' => '', 'date_given' => ''];
        }
        self::assertSame([], $validator->validate($input)['supplements']);
        foreach (VisitValidator::SUPPLEMENTS as $type => $label) {
            $input['supplements'][$type] = ['dose' => ' FAKE recorded dose ', 'date_given' => '2026-01-01'];
        }
        $input['referred'] = '1';
        $input['referred_to'] = ' FAKE Facility ';
        $data = $validator->validate($input, new DateTimeImmutable('2026-01-01'));
        self::assertCount(4, $data['supplements']);
        self::assertSame(array_keys(VisitValidator::SUPPLEMENTS), array_column($data['supplements'], 'type'));
        self::assertSame('FAKE recorded dose', $data['supplements'][0]['dose']);
        self::assertSame('FAKE Facility', $data['visit']['referred_to']);
    }
}
