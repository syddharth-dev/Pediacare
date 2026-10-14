<?php

declare(strict_types=1);

namespace Pediacare\Validation;

use DateTimeImmutable;
use InvalidArgumentException;

final class ChildValidator
{
    public const TEXT_FIELDS = [
        'first_name' => ['First name', 255, true], 'middle_name' => ['Middle name', 255, false],
        'last_name' => ['Last name', 255, true], 'suffix' => ['Suffix', 32, false],
        'place_of_birth' => ['Place of birth', 255, true], 'address' => ['Address', 65535, true],
        'mother_name' => ["Mother's name", 255, true], 'father_name' => ["Father's name", 255, false],
        'guardian_name' => ['Guardian name', 255, true], 'guardian_relation' => ['Guardian relationship', 100, true],
        'contact_no' => ['Contact number', 32, true], 'philhealth_no' => ['PhilHealth number', 32, false],
        'notes' => ['Notes', 65535, false],
    ];

    public function validate(array $input, ?DateTimeImmutable $today = null, bool $newRegistration = true): array
    {
        $data = [];
        foreach (self::TEXT_FIELDS as $field => [$label, $limit, $required]) {
            $value = $input[$field] ?? '';
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new InvalidArgumentException('Enter a valid value for ' . strtolower($label) . '.');
            }
            $value = trim($value);
            if (in_array($field, ['first_name', 'middle_name', 'last_name', 'suffix'], true)) {
                $value = trim(preg_replace('/\s+/u', ' ', $value));
            }
            if (($required && preg_match('/\A\s*\z/u', $value) === 1) || mb_strlen($value, 'UTF-8') > $limit
                || (in_array($field, ['address', 'notes'], true) && strlen($value) > 65535)) {
                throw new InvalidArgumentException('Enter ' . strtolower($label) . ' within the field limit.');
            }
            $data[$field] = !$required && $value === '' ? null : $value;
        }

        $sex = $input['sex'] ?? null;
        if (!is_string($sex) || !in_array($sex, ['M', 'F'], true)) {
            throw new InvalidArgumentException('Select male or female.');
        }
        $data['sex'] = $sex;
        $birthDate = $input['date_of_birth'] ?? null;
        if (!is_string($birthDate) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $birthDate) !== 1) {
            throw new InvalidArgumentException('Enter a valid date of birth.');
        }
        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        if ($birth === false || $birth->format('Y-m-d') !== $birthDate || $birth > $today || $birthDate < '1000-01-01') {
            throw new InvalidArgumentException('Enter a valid date of birth on or before today.');
        }
        // Spec §4.2: new registrations are limited to 0–59 completed months.
        if ($newRegistration && $birth->diff($today)->y >= 5) {
            throw new InvalidArgumentException('New registrations must be for children aged 0–59 months.');
        }
        $data['date_of_birth'] = $birthDate;
        $purok = $input['purok_id'] ?? null;
        if (!is_string($purok) || filter_var($purok, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) === false) {
            throw new InvalidArgumentException('Select a valid purok or sitio.');
        }
        $data['purok_id'] = (int) $purok;
        foreach (['birth_weight_kg' => [2, '99.99'], 'birth_length_cm' => [1, '999.9']] as $field => [$scale, $maximum]) {
            $value = $input[$field] ?? '';
            if (!is_string($value)) {
                throw new InvalidArgumentException('Enter a valid birth measurement.');
            }
            $value = trim($value);
            if ($value === '') {
                $data[$field] = null;
                continue;
            }
            // These limits reflect SQL DECIMAL storage, not medical classification thresholds.
            if (preg_match('/\A[0-9]+(?:\.[0-9]{1,' . $scale . '})?\z/', $value) !== 1
                || (float) $value <= 0 || (float) $value > (float) $maximum) {
                throw new InvalidArgumentException('Enter a positive birth measurement within the field limit.');
            }
            $data[$field] = number_format((float) $value, $scale, '.', '');
        }

        return $data;
    }

    public function reason(array $input): string
    {
        $reason = $input['reason'] ?? null;
        if (!is_string($reason) || !mb_check_encoding($reason, 'UTF-8') || str_contains($reason, "\0")
            || preg_match('/\A\s*\z/u', $reason) === 1 || strlen($reason) > 65535) {
            throw new InvalidArgumentException('Enter a reason within the field limit.');
        }
        if (($input['confirm'] ?? null) !== '1') {
            throw new InvalidArgumentException('Confirm the archive or restore before saving.');
        }

        return trim($reason);
    }
}
