<?php

declare(strict_types=1);

namespace Pediacare\Validation;

use InvalidArgumentException;

final class DefaulterFilterValidator
{
    public function validate(array $input): array
    {
        $filters = [];
        foreach (['purok_id', 'vaccine_id', 'page'] as $field) {
            $value = $input[$field] ?? ($field === 'page' ? '1' : '');
            $maximum = $field === 'page' ? 2147483647 : 4294967295;
            if (!is_string($value) || ($value !== '' && (preg_match('/\A[1-9][0-9]{0,9}\z/', $value) !== 1
                || (float) $value > $maximum)) || ($field === 'page' && $value === '')) {
                throw new InvalidArgumentException('Choose valid purok, vaccine and page filters.');
            }
            $filters[$field] = $value;
        }

        return $filters;
    }
}
