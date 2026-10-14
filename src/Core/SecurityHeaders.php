<?php

declare(strict_types=1);

namespace Pediacare\Core;

final class SecurityHeaders
{
    public const VALUES = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'same-origin',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; "
            . "object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'",
    ];

    public static function send(): void
    {
        foreach (self::VALUES as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
