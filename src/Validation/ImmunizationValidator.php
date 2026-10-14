<?php

declare(strict_types=1);

namespace Pediacare\Validation;

use DateTimeImmutable;
use InvalidArgumentException;

final class ImmunizationValidator
{
    public function validate(array $input, ?DateTimeImmutable $today = null): array
    {
        $id = $input['schedule_dose_id'] ?? null;
        if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,9}\z/', $id) !== 1 || (float) $id > 4294967295) {
            throw new InvalidArgumentException('Choose a scheduled vaccine dose.');
        }
        $value = $input['date_given'] ?? null;
        if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) !== 1) {
            throw new InvalidArgumentException('Enter a valid vaccination date.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '1000-01-01'
            || $value > ($today ?? new DateTimeImmutable('today'))->format('Y-m-d')) {
            throw new InvalidArgumentException('Enter a valid vaccination date on or before today.');
        }
        $elsewhere = $input['given_elsewhere'] ?? null;
        if (!in_array($elsewhere, ['0', '1'], true)) {
            throw new InvalidArgumentException('Choose whether the vaccine was given elsewhere.');
        }
        $data = ['schedule_dose_id' => (int) $id, 'date_given' => $value, 'given_elsewhere' => (int) $elsewhere];
        foreach (['lot_number' => 100, 'site' => 150, 'place_given' => 255] as $field => $limit) {
            $text = $input[$field] ?? '';
            if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")
                || mb_strlen(trim($text)) > $limit || ($field === 'place_given' && trim($text) === '')) {
                throw new InvalidArgumentException('Enter ' . str_replace('_', ' ', $field) . ' within the ' . $limit . '-character limit.');
            }
            $data[$field] = trim($text) === '' ? null : trim($text);
        }

        return $data;
    }
}
