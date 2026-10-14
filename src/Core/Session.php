<?php

declare(strict_types=1);

namespace Pediacare\Core;

use LogicException;
use RuntimeException;

final class Session
{
    public function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;

        return is_int($id) ? $id : null;
    }

    public function renew(?int $userId = null): void
    {
        $_SESSION = [];

        if (session_status() !== PHP_SESSION_ACTIVE || !session_regenerate_id(true)) {
            throw new RuntimeException('Session could not be renewed.');
        }

        $_SESSION['_last_activity'] = time();

        if ($userId !== null) {
            $_SESSION['user_id'] = $userId;
        }
    }

    public function start(bool $https, ?int $now = null): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new LogicException('Session must be started only once per request.');
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        // Match the idle window: https://www.php.net/manual/en/session.configuration.php
        ini_set('session.gc_maxlifetime', '1800');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException('Session could not be started.');
        }

        $now ??= time();
        $lastActivity = $_SESSION['_last_activity'] ?? null;

        if ($_SESSION !== [] && (!is_int($lastActivity) || $now - $lastActivity >= 1800)) {
            $_SESSION = [];

            if (!session_regenerate_id(true)) {
                throw new RuntimeException('Session could not be renewed.');
            }
        }

        $_SESSION['_last_activity'] = $now;
    }
}
