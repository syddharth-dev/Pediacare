<?php

declare(strict_types=1);

namespace Pediacare\Services;

use InvalidArgumentException;
use PDOException;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Validation\PurokValidator;

final class PurokService
{
    public function __construct(private readonly PurokRepository $puroks, private readonly PurokValidator $validator)
    {
    }

    public function all(): array
    {
        return $this->puroks->all();
    }

    public function create(array $input): void
    {
        $this->save(null, $this->validator->name($input));
    }

    public function rename(string $id, array $input): void
    {
        $value = $this->validator->id($id);
        $name = $this->validator->name($input);

        if ($this->puroks->findById($value) === null) {
            throw new InvalidArgumentException('Purok or sitio not found. Refresh the list and try again.');
        }

        $this->save($value, $name);
    }

    private function save(?int $id, string $name): void
    {
        try {
            if ($id === null) {
                $this->puroks->insert($name);
            } else {
                $this->puroks->rename($id, $name);
            }
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new InvalidArgumentException('A purok or sitio already uses this name. Choose a different name.');
            }

            throw $error;
        }
    }
}
