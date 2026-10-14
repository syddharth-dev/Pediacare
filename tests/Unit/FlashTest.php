<?php

declare(strict_types=1);

use Pediacare\Core\Flash;
use PHPUnit\Framework\TestCase;

final class FlashTest extends TestCase
{
    public function test_T05_messages_survive_a_new_flash_instance_and_are_consumed_once(): void
    {
        $session = ['unrelated' => 'FAKE_SESSION_VALUE'];
        (new Flash($session))->add('FAKE_SAVED_MESSAGE');
        (new Flash($session))->add('FAKE_WARNING_MESSAGE', 'warning');
        $flash = new Flash($session);

        self::assertSame([
            ['type' => 'success', 'message' => 'FAKE_SAVED_MESSAGE'],
            ['type' => 'warning', 'message' => 'FAKE_WARNING_MESSAGE'],
        ], $flash->pull());
        self::assertSame([], $flash->pull());
        self::assertSame(['unrelated' => 'FAKE_SESSION_VALUE'], $session);
    }

    public function test_T05_unknown_message_type_is_rejected_without_storing_it(): void
    {
        $session = [];

        try {
            (new Flash($session))->add('FAKE_MESSAGE', '<script>');
            self::fail('Expected an invalid message type to fail.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Flash message type is invalid.', $error->getMessage());
        }

        self::assertSame([], $session);
    }
}
