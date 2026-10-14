<?php

declare(strict_types=1);

namespace Pediacare\Support;

final class MeasurementConfirmation
{
    private array $session;

    public function __construct(array &$session)
    {
        $this->session = &$session;
    }

    public function issue(array $data): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session['_measurement_confirmation'] = ['token' => $token, 'fingerprint' => $this->fingerprint($data)];

        return $token;
    }

    public function verify(array $data, ?string $token): bool
    {
        $stored = $this->session['_measurement_confirmation'] ?? null;

        return is_array($stored) && $token !== null && hash_equals($stored['token'], $token)
            && hash_equals($stored['fingerprint'], $this->fingerprint($data));
    }

    public function clear(): void
    {
        unset($this->session['_measurement_confirmation']);
    }

    private function fingerprint(array $data): string
    {
        ksort($data);

        return hash('sha256', serialize($data));
    }
}
