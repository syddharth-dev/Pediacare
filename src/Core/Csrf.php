<?php

declare(strict_types=1);

namespace Pediacare\Core;

final class Csrf
{
    private array $session;

    public function __construct(array &$session)
    {
        $this->session = &$session;
    }

    public function token(): string
    {
        if (!isset($this->session['_csrf_token'])) {
            $this->session['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $this->session['_csrf_token'];
    }

    public function verify(?string $token): bool
    {
        $stored = $this->session['_csrf_token'] ?? null;

        return $token !== null && is_string($stored) && hash_equals($stored, $token);
    }
}
