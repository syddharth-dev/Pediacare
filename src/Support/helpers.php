<?php

declare(strict_types=1);

function e(string|int|float|bool|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_field(string $token): string
{
    return '<input type="hidden" name="_csrf_token" value="' . e($token) . '">';
}
