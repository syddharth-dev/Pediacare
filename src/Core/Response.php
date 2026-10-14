<?php

declare(strict_types=1);

namespace Pediacare\Core;

use InvalidArgumentException;

final class Response
{
    public readonly array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        array $headers = ['Content-Type' => 'text/html; charset=UTF-8']
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Response status is invalid.');
        }

        foreach ($headers as $name => $value) {
            if (
                !is_string($name) || preg_match('/\A[A-Za-z0-9-]+\z/', $name) !== 1
                || !is_string($value) || preg_match('/[\r\n\x00]/', $value) === 1
            ) {
                throw new InvalidArgumentException('Response header is invalid.');
            }
        }

        // Policy cannot be weakened by a controller, including differently cased header names.
        $protected = array_map('strtolower', array_keys(SecurityHeaders::VALUES));

        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower($name), $protected, true)) {
                unset($headers[$name]);
            }
        }

        $this->headers = SecurityHeaders::VALUES + $headers;
    }

    public static function redirect(string $path, int $status = 303): self
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            throw new InvalidArgumentException('Redirect must use a local path.');
        }

        return new self('', $status, ['Location' => $path]);
    }

    public function send(bool $headOnly = false): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if (!$headOnly) {
            echo $this->body;
        }
    }
}
