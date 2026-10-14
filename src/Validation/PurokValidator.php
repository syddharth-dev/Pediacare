<?php

declare(strict_types=1);

namespace Pediacare\Validation;

use InvalidArgumentException;

final class PurokValidator
{
    public function name(array $input): string
    {
        $name = $input['name'] ?? null;

        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8') || str_contains($name, "\0")
            || trim($name) === '' || mb_strlen(trim($name), 'UTF-8') > 255) {
            throw new InvalidArgumentException('Enter a purok or sitio name of up to 255 characters.');
        }

        return trim($name);
    }

    public function id(string $id): int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        if ($value === false) {
            throw new InvalidArgumentException('Select a valid purok or sitio.');
        }

        return $value;
    }
}
