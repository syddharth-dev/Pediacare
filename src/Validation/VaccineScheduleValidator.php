<?php

declare(strict_types=1);

namespace Pediacare\Validation;

use InvalidArgumentException;

final class VaccineScheduleValidator
{
    public function id(string $value): int
    {
        return $this->integer($value, 'record', 1);
    }

    public function vaccine(array $input, bool $creating): array
    {
        $name = $input['name'] ?? null;
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8') || str_contains($name, "\0")
            || trim($name) === '' || mb_strlen(trim($name)) > 150) {
            throw new InvalidArgumentException('Enter a vaccine name of up to 150 characters.');
        }
        $result = ['name' => trim($name), 'active' => $this->flag($input['active'] ?? null)];
        if ($creating) {
            $code = $input['code'] ?? null;
            if (!is_string($code) || preg_match('/\A[A-Z0-9_]{1,32}\z/', $code) !== 1) {
                throw new InvalidArgumentException('Enter a unique vaccine code using 1–32 uppercase letters, digits or underscores.');
            }
            $result['code'] = $code;
        }

        return $result;
    }

    public function dose(array $input): array
    {
        $ageUnit = $input['age_unit'] ?? null;
        $intervalUnit = $input['interval_unit'] ?? null;
        if (!in_array($ageUnit, ['days', 'months'], true) || !in_array($intervalUnit, ['days', 'months'], true)) {
            throw new InvalidArgumentException('Choose days or calendar months for each age and interval.');
        }
        $age = $this->integer($input['age_value'] ?? null, 'recommended age');
        $interval = $this->integer($input['interval_value'] ?? null, 'minimum interval');

        return ['dose_number' => $this->integer($input['dose_number'] ?? null, 'dose number', 1),
            'recommended_age_days' => $ageUnit === 'days' ? $age : null,
            'recommended_age_months' => $ageUnit === 'months' ? $age : null,
            'grace_days' => $this->integer($input['grace_days'] ?? null, 'grace period'),
            'min_interval_days' => $intervalUnit === 'days' ? $interval : null,
            'min_interval_months' => $intervalUnit === 'months' ? $interval : null,
            'counts_toward_fic' => $this->flag($input['counts_toward_fic'] ?? null),
            'sort_order' => $this->integer($input['sort_order'] ?? null, 'display order')];
    }

    private function flag(mixed $value): int
    {
        if ($value !== '0' && $value !== '1') {
            throw new InvalidArgumentException('Choose Yes or No for each setting.');
        }

        return (int) $value;
    }

    private function integer(mixed $value, string $label, int $minimum = 0): int
    {
        if (!is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/', $value) !== 1
            || (float) $value > 4294967295 || (int) $value < $minimum) {
            throw new InvalidArgumentException('Enter a whole number from ' . $minimum . ' to 4294967295 for ' . $label . '.');
        }

        return (int) $value;
    }
}
