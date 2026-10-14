<?php

declare(strict_types=1);

namespace Pediacare\Core;

use InvalidArgumentException;

final class Request
{
    public readonly string $method;

    public function __construct(
        string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly string $ipAddress = '127.0.0.1'
    ) {
        $this->method = strtoupper($method);

        if ($path === '' || $path[0] !== '/' || preg_match('/[\x00-\x20\x7f]/', $path) === 1) {
            throw new InvalidArgumentException('Request path is invalid.');
        }
    }

    public static function fromGlobals(): self
    {
        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0],
            $_GET,
            $_POST,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        );
    }
}
