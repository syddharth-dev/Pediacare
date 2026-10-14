<?php

declare(strict_types=1);

namespace Pediacare\Core;

use InvalidArgumentException;

final class Flash
{
    private array $session;

    public function __construct(array &$session)
    {
        $this->session = &$session;
    }

    public function add(string $message, string $type = 'success'): void
    {
        if (!in_array($type, ['success', 'error', 'warning', 'info'], true)) {
            throw new InvalidArgumentException('Flash message type is invalid.');
        }

        $this->session['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type: string, message: string}> */
    public function pull(): array
    {
        $messages = $this->session['_flash'] ?? [];
        unset($this->session['_flash']);

        return $messages;
    }
}
