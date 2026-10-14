<?php

declare(strict_types=1);

use Pediacare\Repositories\AuditRepository;
use Pediacare\Repositories\ChildRepository;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Repositories\RecordCounterRepository;
use Pediacare\Services\ChildService;
use Pediacare\Support\AuditLogger;
use Pediacare\Support\RecordNumberGenerator;
use Pediacare\Validation\ChildValidator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ChildServiceTest extends TestCase
{
    private PDO $pdo;
    private ChildRepository $children;
    private PurokRepository $puroks;
    private RecordCounterRepository $counters;
    private AuditRepository $audit;
    private bool $active = false;
    private ChildService $service;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('inTransaction')->willReturnCallback(fn (): bool => $this->active);
        $this->pdo->method('beginTransaction')->willReturnCallback(function (): bool { $this->active = true; return true; });
        $this->pdo->method('commit')->willReturnCallback(function (): bool { $this->active = false; return true; });
        $this->pdo->method('rollBack')->willReturnCallback(function (): bool { $this->active = false; return true; });
        $this->children = $this->createMock(ChildRepository::class);
        $this->puroks = $this->createMock(PurokRepository::class);
        $this->counters = $this->createMock(RecordCounterRepository::class);
        $this->audit = $this->createMock(AuditRepository::class);
        $this->service = new ChildService($this->pdo, $this->children, $this->puroks, new ChildValidator(),
            new RecordNumberGenerator($this->counters), new AuditLogger($this->audit));
    }

    public function test_AC11_creation_and_audit_run_inside_same_transaction_with_authenticated_actor(): void
    {
        $this->puroks->method('findById')->willReturn(['id' => 7]);
        $this->children->method('insert')->willReturnCallback(function (array $data): int {
            self::assertTrue($this->active);
            self::assertSame(42, $data['created_by']);
            self::assertMatchesRegularExpression('/^PC-[0-9]{4}-00001$/', $data['record_no']);
            return 9;
        });
        $this->audit->expects(self::once())->method('insert')->willReturnCallback(
            function (int $user, string $action, string $entity, int $id, string $changes): void {
                self::assertTrue($this->active);
                self::assertSame([42, 'CREATE', 'children', 9], [$user, $action, $entity, $id]);
                $json = json_decode($changes, true, 512, JSON_THROW_ON_ERROR);
                self::assertSame([], $json['before']);
                self::assertSame('FAKE Child', $json['after']['first_name']);
                self::assertArrayNotHasKey('confirm_duplicate', $json['after']);
            }
        );
        $result = $this->service->register(require dirname(__DIR__) . '/Fixtures/child.php', 42);
        self::assertNotNull($result->recordNumber);
        self::assertSame(9, $result->childId);
        self::assertFalse($this->active);
    }

    public function test_AC04_duplicate_warning_rolls_back_without_creating_child_or_audit(): void
    {
        $this->puroks->method('findById')->willReturn(['id' => 7]);
        $this->children->method('duplicates')->willReturn([['record_no' => 'PC-2026-00001']]);
        $this->children->expects(self::never())->method('insert');
        $this->audit->expects(self::never())->method('insert');
        $this->pdo->expects(self::once())->method('rollBack');
        $result = $this->service->register(require dirname(__DIR__) . '/Fixtures/child.php', 42);
        self::assertNull($result->recordNumber);
        self::assertNull($result->childId);
        self::assertCount(1, $result->duplicates);
    }

    public function test_AC11_audit_failure_rolls_back_and_propagates(): void
    {
        $this->puroks->method('findById')->willReturn(['id' => 7]);
        $this->children->method('insert')->willReturn(9);
        $this->audit->method('insert')->willThrowException(new PDOException('FAKE failure'));
        $this->pdo->expects(self::once())->method('rollBack');
        $this->pdo->expects(self::never())->method('commit');
        $this->expectException(PDOException::class);
        $this->service->register(require dirname(__DIR__) . '/Fixtures/child.php', 42);
    }

    public function test_AC04_missing_purok_is_rejected_before_transaction(): void
    {
        $this->puroks->method('findById')->willReturn(null);
        $this->pdo->expects(self::never())->method('beginTransaction');
        $this->expectException(InvalidArgumentException::class);
        $this->service->register(require dirname(__DIR__) . '/Fixtures/child.php', 42);
    }

    private function existingChild(string $action): array
    {
        $data = (new ChildValidator())->validate(require dirname(__DIR__) . '/Fixtures/child.php');

        return $data + ['id' => 9, 'record_no' => 'PC-2026-00009', 'created_by' => 2,
            'archived_at' => $action === 'restore' ? '2026-01-01 12:00:00' : null,
            'archive_reason' => $action === 'restore' ? 'FAKE Old Reason' : null];
    }

    private function changeInput(string $action): array
    {
        return $action === 'update' ? array_replace(require dirname(__DIR__) . '/Fixtures/child.php',
            ['first_name' => 'FAKE Changed', 'created_by' => '999', 'record_no' => 'FORGED'])
            : ['reason' => 'FAKE Reason', 'confirm' => '1'];
    }

    public static function changes(): array
    {
        return [['update'], ['archive'], ['restore']];
    }

    #[DataProvider('changes')]
    public function test_AC11_changes_lock_the_current_row_and_log_only_changed_fields_in_same_transaction(string $action): void
    {
        $this->puroks->method('findById')->willReturn(['id' => 7]);
        $this->children->expects(self::once())->method('lockById')->with(9)->willReturnCallback(function () use ($action): array {
            self::assertTrue($this->active);
            return $this->existingChild($action);
        });
        $this->children->expects($action === 'update' ? self::once() : self::never())->method('update');
        $this->children->expects($action === 'update' ? self::never() : self::once())->method('setArchive');
        $this->audit->expects(self::once())->method('insert')->willReturnCallback(
            function (int $user, string $loggedAction, string $entity, int $id, string $changes) use ($action): void {
                self::assertTrue($this->active);
                self::assertSame([42, strtoupper($action), 'children', 9], [$user, $loggedAction, $entity, $id]);
                $json = json_decode($changes, true, 512, JSON_THROW_ON_ERROR);
                self::assertArrayNotHasKey('created_by', $json['after']);
                self::assertArrayNotHasKey('record_no', $json['after']);
                if ($action === 'update') {
                    self::assertSame(['first_name' => 'FAKE Child'], $json['before']);
                    self::assertSame(['first_name' => 'FAKE Changed'], $json['after']);
                } elseif ($action === 'archive') {
                    self::assertNull($json['before']['archived_at']);
                    self::assertNotEmpty($json['after']['archived_at']);
                    self::assertSame('FAKE Reason', $json['after']['archive_reason']);
                } else {
                    self::assertSame('FAKE Old Reason', $json['before']['archive_reason']);
                    self::assertNull($json['after']['archived_at']);
                    self::assertNull($json['after']['archive_reason']);
                    self::assertSame('FAKE Reason', $json['after']['restore_reason']);
                }
            }
        );
        $this->pdo->expects(self::once())->method('commit');
        $this->service->$action('9', $this->changeInput($action), 42);
        self::assertFalse($this->active);
    }

    #[DataProvider('changes')]
    public function test_AC11_audit_failure_rolls_back_every_kind_of_child_change(string $action): void
    {
        $this->puroks->method('findById')->willReturn(['id' => 7]);
        $this->children->method('lockById')->willReturn($this->existingChild($action));
        $this->audit->method('insert')->willThrowException(new RuntimeException('FAKE audit failure'));
        $this->pdo->expects(self::once())->method('rollBack');
        $this->pdo->expects(self::never())->method('commit');
        $this->expectException(RuntimeException::class);
        $this->service->$action('9', $this->changeInput($action), 42);
    }

    #[DataProvider('changes')]
    public function test_AC11_existing_outer_transaction_is_not_committed_or_rolled_back(string $action): void
    {
        $this->active = true;
        $this->puroks->method('findById')->willReturn(['id' => 7]);
        $this->children->expects(self::never())->method('lockById');
        $this->pdo->expects(self::never())->method('commit');
        $this->pdo->expects(self::never())->method('rollBack');
        $this->expectException(LogicException::class);
        $this->service->$action('9', $this->changeInput($action), 42);
    }
}
