<?php

declare(strict_types=1);

namespace Pediacare\Services;

use DateTimeImmutable;
use Pediacare\Core\Session;
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\UserRepository;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginAttemptRepository $attempts,
        private readonly Session $session
    ) {
    }

    public function login(string $email, string $password, string $ip, ?DateTimeImmutable $now = null): bool
    {
        $email = strtolower(trim($email));

        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $password === '' || str_contains($password, "\0") || filter_var($ip, FILTER_VALIDATE_IP) === false
        ) {
            return false;
        }

        $ip = inet_ntop(inet_pton($ip));

        $locks = [
            'pc-auth-' . substr(hash('sha256', 'email:' . $email), 0, 56),
            'pc-auth-' . substr(hash('sha256', 'ip:' . $ip), 0, 56),
        ];
        sort($locks);
        $held = [];

        try {
            foreach ($locks as $lock) {
                $this->attempts->lock($lock);
                $held[] = $lock;
            }

            $now ??= new DateTimeImmutable();
            $counts = $this->attempts->failures($email, $ip, $now->modify('-15 minutes')->format('Y-m-d H:i:s'));

            if ($counts['email'] >= 5 || $counts['ip'] >= 5) {
                return false;
            }

            $user = $this->users->findByEmail($email);
            // Use a valid dummy hash so unknown accounts still perform password verification.
            $hash = $user['password_hash'] ?? '$2y$12$4Umg0rCJwMswRw/l.SwHvuQV01coP0eWmGzd61QH2RvAOMANUBGC.';
            $valid = password_verify($password, $hash);
            // bcrypt truncates at 72 bytes; reject longer input rather than accepting a shared prefix.
            $valid = $valid && !(password_get_info($hash)['algoName'] === 'bcrypt' && strlen($password) > 72);
            $valid = $valid && $user !== null && (int) $user['active'] === 1;
            $at = $now->format('Y-m-d H:i:s');
            $this->attempts->record($email, $ip, $at, $valid);

            if (!$valid) {
                return false;
            }

            $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

            if (password_needs_rehash($hash, $algorithm)) {
                $hash = password_hash($password, $algorithm);
            }

            $this->users->recordLogin((int) $user['id'], $hash, $at);
            $this->session->renew((int) $user['id']);

            return true;
        } finally {
            foreach (array_reverse($held) as $lock) {
                $this->attempts->unlock($lock);
            }
        }
    }

    public function currentUser(): ?array
    {
        $id = $this->session->userId();
        $user = $id === null ? null : $this->users->findById($id);

        return $user !== null && (int) $user['active'] === 1 ? $user : null;
    }

    public function logout(): void
    {
        $this->session->renew();
    }
}
