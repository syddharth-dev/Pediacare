<?php

declare(strict_types=1);

namespace Pediacare\Validation;

use DateTimeImmutable;
use InvalidArgumentException;

final class ReportFilterValidator
{
    public function listing(array $input): array
    {
        $page = $input['page'] ?? '1';
        $archived = $input['include_archived'] ?? '0';
        if (!is_string($page) || preg_match('/\A[1-9][0-9]{0,9}\z/', $page) !== 1
            || (float) $page > 2147483647 || !in_array($archived, ['0', '1'], true)) {
            throw new InvalidArgumentException('Choose a valid page and Include archived option.');
        }

        return ['page' => $page, 'include_archived' => $archived];
    }

    public function monthly(array $input): array
    {
        $month = $input['month'] ?? date('Y-m');
        if (!is_string($month) || preg_match('/\A[0-9]{4}-[0-9]{2}\z/', $month) !== 1) {
            throw new InvalidArgumentException('Enter a valid reporting month.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        if ($date === false || $date->format('Y-m') !== $month || $month < '1000-01') {
            throw new InvalidArgumentException('Enter a valid reporting month.');
        }

        return $this->validate(['from' => $month . '-01', 'to' => $date->format('Y-m-t'),
            'include_archived' => $input['include_archived'] ?? '0']) + ['month' => $month];
    }

    public function validate(array $input): array
    {
        $filters = [];
        foreach (['from' => date('Y-m-01'), 'to' => date('Y-m-d')] as $field => $default) {
            $value = $input[$field] ?? $default;
            if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) !== 1) {
                throw new InvalidArgumentException('Enter valid start and end dates.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value || $value < '1000-01-01') {
                throw new InvalidArgumentException('Enter valid start and end dates.');
            }
            $filters[$field] = $value;
        }
        if ($filters['from'] > $filters['to']) {
            throw new InvalidArgumentException('The end date must be on or after the start date.');
        }
        $archived = $input['include_archived'] ?? '0';
        if (!in_array($archived, ['0', '1'], true)) {
            throw new InvalidArgumentException('Choose a valid Include archived option.');
        }

        return $filters + ['include_archived' => $archived];
    }
}
