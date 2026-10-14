<?php

declare(strict_types=1);

namespace Pediacare\Support;

final class DuplicateConfirmation
{
    private array $session;

    public function __construct(array &$session)
    {
        $this->session = &$session;
    }

    public function issue(array $input): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session['_child_duplicate_confirmation'] = ['token' => $token, 'fingerprint' => $this->fingerprint($input)];

        return $token;
    }

    public function verify(array $input, ?string $token): bool
    {
        $stored = $this->session['_child_duplicate_confirmation'] ?? null;

        return is_array($stored) && $token !== null && hash_equals($stored['token'], $token)
            && hash_equals($stored['fingerprint'], $this->fingerprint($input));
    }

    public function clear(): void
    {
        unset($this->session['_child_duplicate_confirmation']);
    }

    private function fingerprint(array $input): string
    {
        unset($input['_csrf_token'], $input['duplicate_token'], $input['confirm_duplicate']);
        ksort($input);

        return hash('sha256', serialize($input));
    }
}
