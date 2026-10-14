<?php

declare(strict_types=1);

namespace Pediacare\Validation;

use InvalidArgumentException;
use Pediacare\Support\Permissions;

final class UserValidator
{
    public function create(array $input): array
    {
        $name = $input['name'] ?? null;
        $email = $input['email'] ?? null;
        $password = $input['password'] ?? null;

        if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 255) {
            throw new InvalidArgumentException('Enter a name of up to 255 characters.');
        }

        if (!is_string($email) || strlen(trim($email)) > 254 || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }

        if (!is_string($password) || $password === '' || str_contains($password, "\0")
            || (!defined('PASSWORD_ARGON2ID') && strlen($password) > 72)
        ) {
            throw new InvalidArgumentException('Enter a valid password.');
        }

        return [
            'name' => trim($name), 'email' => strtolower(trim($email)), 'password' => $password,
            'role' => $this->role($input),
        ];
    }

    public function role(array $input): string
    {
        $role = $input['role'] ?? null;

        if (!is_string($role) || !Permissions::allows($role, 'session.logout')) {
            throw new InvalidArgumentException('Select a valid role.');
        }

        return $role;
    }

    public function id(string $id): int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);

        if ($value === false) {
            throw new InvalidArgumentException('Account ID is invalid.');
        }

        return $value;
    }
}
