<?php

declare(strict_types=1);

namespace Pediacare\Services;

use InvalidArgumentException;
use Pediacare\Repositories\UserRepository;

final class AdminSeedService
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function run(array $config): bool
    {
        if ($this->users->adminExists()) {
            return false;
        }

        $name = $config['name'] ?? null;
        $email = $config['email'] ?? null;
        $password = $config['password'] ?? null;
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 255
            || !is_string($email) || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || !is_string($password) || $password === '' || str_contains($password, "\0")
            || ($algorithm === PASSWORD_BCRYPT && strlen($password) > 72)
        ) {
            throw new InvalidArgumentException('First Admin configuration is invalid.');
        }

        $this->users->insertAdmin(trim($name), $email, password_hash($password, $algorithm));

        return true;
    }
}
