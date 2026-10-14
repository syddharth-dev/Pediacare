<?php

declare(strict_types=1);

namespace Pediacare\Validation;

use DateTimeImmutable;
use InvalidArgumentException;
use Pediacare\Domain\Enums\MeasurementPosition;

final class MeasurementValidator
{
    public const FIELDS = [
        'weight_kg' => ['Weight (kg)', 2, 999.99, true],
        'height_cm' => ['Length or height (cm)', 1, 999.9, true],
        'muac_cm' => ['MUAC (cm)', 1, 999.9, false],
    ];

    public function validate(array $input, ?DateTimeImmutable $today = null): array
    {
        $date = $input['measured_at'] ?? null;
        if (!is_string($date) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) !== 1) {
            throw new InvalidArgumentException('Enter a valid measurement date.');
        }
        $measured = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($measured === false || $measured->format('Y-m-d') !== $date || $date < '1000-01-01'
            || $date > ($today ?? new DateTimeImmutable('today'))->format('Y-m-d')) {
            throw new InvalidArgumentException('Enter a valid measurement date on or before today.');
        }
        $data = ['measured_at' => $date];
        foreach (self::FIELDS as $field => [$label, $scale, $maximum, $required]) {
            $value = $input[$field] ?? '';
            if (!is_string($value)) {
                throw new InvalidArgumentException('Enter a valid value for ' . $label . '.');
            }
            $value = trim($value);
            if (!$required && $value === '') {
                $data[$field] = null;
                continue;
            }
            // Limits and precision match plan.md §5 SQL DECIMAL fields; they are not clinical cut-offs.
            if (preg_match('/\A[0-9]+(?:\.[0-9]{1,' . $scale . '})?\z/', $value) !== 1
                || (float) $value <= 0 || (float) $value > $maximum) {
                throw new InvalidArgumentException('Enter a positive value for ' . $label . ' within the field limit.');
            }
            $data[$field] = number_format((float) $value, $scale, '.', '');
        }
        $position = $input['position'] ?? null;
        if (!is_string($position) || MeasurementPosition::tryFrom($position) === null) {
            throw new InvalidArgumentException('Select measured lying or standing.');
        }
        $data['position'] = $position;

        return $data;
    }
}
