<?php

declare(strict_types=1);

namespace Pediacare\Validation;

use InvalidArgumentException;

final class ChildFilterValidator
{
    public const AGE_GROUPS = [
        '0-11' => '0–11 months',
        '12-23' => '12–23 months',
        '24-35' => '24–35 months',
        '36-47' => '36–47 months',
        '48-59' => '48–59 months',
        '60+' => '60+ months',
    ];

    public function validate(array $input): array
    {
        $filters = [];
        foreach (['q', 'purok_id', 'sex', 'age_group', 'include_archived', 'page'] as $key) {
            $value = $input[$key] ?? ($key === 'page' ? '1' : '');
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new InvalidArgumentException('Choose valid child filters and try again.');
            }
            $filters[$key] = trim($value);
        }
        if (mb_strlen($filters['q'], 'UTF-8') > 255) {
            throw new InvalidArgumentException('Keep the search text within 255 characters.');
        }
        if ($filters['purok_id'] !== '' && filter_var($filters['purok_id'], FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) === false) {
            throw new InvalidArgumentException('Choose a valid purok or sitio.');
        }
        if (!in_array($filters['sex'], ['', 'M', 'F'], true)) {
            throw new InvalidArgumentException('Choose male, female, or all sexes.');
        }
        if ($filters['age_group'] !== '' && !isset(self::AGE_GROUPS[$filters['age_group']])) {
            throw new InvalidArgumentException('Choose one of the listed age groups.');
        }
        if (!in_array($filters['include_archived'], ['', '0', '1'], true)) {
            throw new InvalidArgumentException('Choose whether to include archived children.');
        }
        if (filter_var($filters['page'], FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false) {
            throw new InvalidArgumentException('Choose a valid page number.');
        }
        $filters['include_archived'] = $filters['include_archived'] === '1';
        $filters['page'] = (int) $filters['page'];

        return $filters;
    }
}
