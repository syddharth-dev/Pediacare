<?php

declare(strict_types=1);

namespace Pediacare\Validation;

use DateTimeImmutable;
use InvalidArgumentException;

final class AuditFilterValidator
{
    public function validate(array $input): array
    {
        $filters = [];

        foreach (['user_id', 'entity', 'from', 'to'] as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || str_contains($value, "\0")) {
                throw new InvalidArgumentException('Choose valid audit filters.');
            }
            $filters[$key] = trim($value);
        }

        if ($filters['user_id'] !== '' && filter_var($filters['user_id'], FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) === false) {
            throw new InvalidArgumentException('Choose a valid user.');
        }
        if ($filters['entity'] !== '' && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $filters['entity']) !== 1) {
            throw new InvalidArgumentException('Choose a valid entity.');
        }
        foreach (['from', 'to'] as $key) {
            if ($filters[$key] === '') {
                continue;
            }
            if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $filters[$key]) !== 1) {
                throw new InvalidArgumentException('Enter valid dates in YYYY-MM-DD format.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filters[$key]);
            if ($date === false || $date->format('Y-m-d') !== $filters[$key] || $filters[$key] < '1000-01-01') {
                throw new InvalidArgumentException('Enter valid dates in YYYY-MM-DD format.');
            }
        }
        if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
            throw new InvalidArgumentException('The end date must be on or after the start date.');
        }

        return $filters;
    }
}
