<?php

declare(strict_types=1);

namespace Pediacare\Services;

use InvalidArgumentException;
use PDOException;
use Pediacare\Repositories\UserRepository;
use Pediacare\Validation\UserValidator;

final class UserService
{
    public function __construct(private readonly UserRepository $users, private readonly UserValidator $validator)
    {
    }

    public function all(): array
    {
        return $this->users->all();
    }

    public function create(array $input): void
    {
        $data = $this->validator->create($input);
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

        try {
            $this->users->insert($data['name'], $data['email'], password_hash($data['password'], $algorithm), $data['role']);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new InvalidArgumentException('An account already uses this email address.');
            }

            throw $error;
        }
    }

    public function deactivate(string $id): void
    {
        $this->users->deactivate($this->existingId($id));
    }

    public function changeRole(string $id, array $input): void
    {
        $role = $this->validator->role($input);
        $this->users->changeRole($this->existingId($id), $role);
    }

    private function existingId(string $id): int
    {
        $value = $this->validator->id($id);

        if ($this->users->findById($value) === null) {
            throw new InvalidArgumentException('Account not found.');
        }

        return $value;
    }
}
