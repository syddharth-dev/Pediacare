<?php

declare(strict_types=1);

use Pediacare\Domain\Enums\AuditAction;
use Pediacare\Repositories\AuditRepository;
use Pediacare\Support\AuditLogger;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    public function test_AC11_update_stores_only_changed_fields_and_preserves_null_and_types(): void
    {
        $repository = $this->createMock(AuditRepository::class);
        $repository->expects(self::once())->method('insert')->willReturnCallback(
            static function (int $user, string $action, string $entity, int $id, string $changes, ?string $ip): void {
                self::assertSame([42, 'UPDATE', 'children', 7, '2001:db8::1'], [$user, $action, $entity, $id, $ip]);
                self::assertSame([
                    'before' => ['notes' => 'FAKE old', 'removed' => null, 'flag' => false],
                    'after' => ['notes' => 'FAKE new', 'flag' => 0, 'added' => null],
                ], json_decode($changes, true, 512, JSON_THROW_ON_ERROR));
            }
        );
        (new AuditLogger($repository, '2001:db8::1'))->log(42, AuditAction::UPDATE, 'children', 7,
            ['name' => 'FAKE unchanged', 'notes' => 'FAKE old', 'removed' => null, 'flag' => false],
            ['name' => 'FAKE unchanged', 'notes' => 'FAKE new', 'flag' => 0, 'added' => null]);
    }

    public function test_AC11_all_actions_record_an_entry_even_when_no_field_changes(): void
    {
        $repository = $this->createMock(AuditRepository::class);
        $repository->expects(self::exactly(4))->method('insert')->with(
            42, self::anything(), 'children', 7, '{"before":{},"after":{}}', null
        );
        $logger = new AuditLogger($repository);

        foreach (AuditAction::cases() as $action) {
            $logger->log(42, $action, 'children', 7, ['notes' => null], ['notes' => null]);
        }
    }

    public function test_AC11_create_and_restore_include_new_values_and_archive_old_values(): void
    {
        $repository = $this->createMock(AuditRepository::class);
        $json = [];
        $repository->method('insert')->willReturnCallback(
            static function (int $user, string $action, string $entity, int $id, string $changes, ?string $ip) use (&$json): void {
                $json[$action] = json_decode($changes, true, 512, JSON_THROW_ON_ERROR);
            }
        );
        $logger = new AuditLogger($repository);
        $logger->log(42, AuditAction::CREATE, 'children', 7, [], ['name' => "FAKE 'quoted Name"]);
        $logger->log(42, AuditAction::ARCHIVE, 'children', 7, ['archived_at' => null], ['archived_at' => '2026-01-01']);
        $logger->log(42, AuditAction::RESTORE, 'children', 7, ['archived_at' => '2026-01-01'], ['archived_at' => null]);
        self::assertSame(['name' => "FAKE 'quoted Name"], $json['CREATE']['after']);
        self::assertSame(['archived_at' => null], $json['ARCHIVE']['before']);
        self::assertSame(['archived_at' => null], $json['RESTORE']['after']);
    }

    public function test_AC11_json_encoding_failure_prevents_persistence(): void
    {
        $repository = $this->createMock(AuditRepository::class);
        $repository->expects(self::never())->method('insert');
        $this->expectException(JsonException::class);
        (new AuditLogger($repository))->log(42, AuditAction::CREATE, 'children', 7, [], ['notes' => "\xB1"]);
    }

    public function test_AC11_repository_failure_propagates_to_the_calling_service(): void
    {
        $repository = $this->createMock(AuditRepository::class);
        $repository->method('insert')->willThrowException(new RuntimeException('FAKE_AUDIT_FAILURE'));
        $this->expectException(RuntimeException::class);
        (new AuditLogger($repository))->log(42, AuditAction::CREATE, 'children', 7, [], ['notes' => 'FAKE']);
    }

    public function test_T13_invalid_identity_is_rejected_before_persistence(): void
    {
        $repository = $this->createMock(AuditRepository::class);
        $repository->expects(self::never())->method('insert');
        $this->expectException(InvalidArgumentException::class);
        (new AuditLogger($repository))->log(0, AuditAction::CREATE, 'children', 7, [], []);
    }
}
