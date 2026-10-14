<?php

declare(strict_types=1);

use Pediacare\Validation\ChildFilterValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChildFilterValidatorTest extends TestCase
{
    public function test_AC12_filters_default_to_active_children_and_normalize_valid_input(): void
    {
        $validator = new ChildFilterValidator();
        self::assertSame(['q' => '', 'purok_id' => '', 'sex' => '', 'age_group' => '',
            'include_archived' => false, 'page' => 1], $validator->validate([]));
        $filters = $validator->validate(['q' => ' FAKE %_! ', 'purok_id' => '7', 'sex' => 'F',
            'age_group' => '60+', 'include_archived' => '1', 'page' => '2']);
        self::assertSame('FAKE %_!', $filters['q']);
        self::assertTrue($filters['include_archived']);
        self::assertSame(2, $filters['page']);
        foreach (array_keys(ChildFilterValidator::AGE_GROUPS) as $group) {
            self::assertSame($group, $validator->validate(['age_group' => $group])['age_group']);
        }
    }

    #[DataProvider('invalidFilters')]
    public function test_AC12_malformed_filters_are_rejected_before_querying(array $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ChildFilterValidator())->validate($input);
    }

    public static function invalidFilters(): array
    {
        return [
            [['q' => ['FAKE']]], [['q' => "FAKE\0"]], [['q' => "\xff"]], [['q' => str_repeat('界', 256)]],
            [['purok_id' => '0']], [['purok_id' => '-1']], [['purok_id' => '4294967296']],
            [['purok_id' => "1 OR 1=1"]], [['sex' => 'X']], [['sex' => ['F']]],
            [['age_group' => '0-59']], [['age_group' => ['0-11']]], [['include_archived' => 'yes']],
            [['include_archived' => ['1']]], [['page' => '0']], [['page' => '-1']],
            [['page' => '1.5']], [['page' => '2147483648']], [['page' => ['1']]],
        ];
    }
}
