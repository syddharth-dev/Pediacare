<?php

declare(strict_types=1);

namespace Pediacare\Core;

use Closure;
use RuntimeException;

final class Container
{
    /** @var array<string, Closure(self): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(public readonly array $config = [])
    {
    }

    /** @param Closure(self): object $factory */
    public function register(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException('Service is not registered.');
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }
}
