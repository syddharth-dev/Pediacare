<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Domain\Enums\AuditAction;
use Pediacare\Repositories\AuditRepository;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\MigrationService;
use Pediacare\Support\AuditLogger;
use PHPUnit\Framework\TestCase;

final class AuditRepositoryTest extends TestCase
{
    private PDO $pdo;
    private array $config;
    private int $userId;

    protected function setUp(): void
    {
        $path = getenv('PEDIACARE_TEST_CONFIG') ?: dirname(__DIR__, 2) . '/config/config.local.php';

        if (!is_file($path)) {
            self::markTestSkipped('Configure pediacare_test to run MySQL integration tests.');
        }

        $this->config = (require $path)['database'];
        self::assertSame('pediacare_test', $this->config['test_name']);
        self::assertNotSame($this->config['name'], $this->config['test_name']);
        $this->config['name'] = $this->config['test_name'];
        $this->pdo = Database::connect($this->config);
        (new MigrationService(new MigrationRepository($this->pdo)))->run(dirname(__DIR__, 2) . '/database/migrations');
        $users = new UserRepository($this->pdo);
        $email = 'fake-audit-' . bin2hex(random_bytes(8)) . '@example.invalid';
        $users->insertAdmin('FAKE Audit Actor', $email, 'FAKE_UNUSED_HASH');
        $this->userId = (int) $users->findByEmail($email)['id'];
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        if (isset($this->userId)) {
            $statement = $this->pdo->prepare('DELETE FROM audit_logs WHERE user_id = :id');
            $statement->execute(['id' => $this->userId]);
            $statement = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
            $statement->execute(['id' => $this->userId]);
        }
    }

    private function entries(PDO $pdo): array
    {
        $statement = $pdo->prepare('SELECT * FROM audit_logs WHERE user_id = :id ORDER BY id');
        $statement->execute(['id' => $this->userId]);

        return $statement->fetchAll();
    }

    public function test_AC11_audit_and_paired_change_commit_together_with_actor_timestamp_and_ip(): void
    {
        $this->pdo->beginTransaction();
        (new UserRepository($this->pdo))->changeRole($this->userId, 'STAFF');
        (new AuditLogger(new AuditRepository($this->pdo), '2001:db8::1'))->log(
            $this->userId, AuditAction::UPDATE, 'users', $this->userId,
            ['role' => 'ADMIN', 'name' => 'FAKE unchanged'], ['role' => 'STAFF', 'name' => 'FAKE unchanged']
        );
        $this->pdo->commit();
        $other = Database::connect($this->config);
        $rows = $this->entries($other);
        self::assertCount(1, $rows);
        self::assertSame($this->userId, (int) $rows[0]['user_id']);
        self::assertSame('UPDATE', $rows[0]['action']);
        self::assertSame('users', $rows[0]['entity']);
        self::assertSame($this->userId, (int) $rows[0]['entity_id']);
        self::assertSame('2001:db8::1', $rows[0]['ip_address']);
        self::assertNotEmpty($rows[0]['created_at']);
        self::assertSame(['before' => ['role' => 'ADMIN'], 'after' => ['role' => 'STAFF']],
            json_decode($rows[0]['changes'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('STAFF', (new UserRepository($other))->findById($this->userId)['role']);
    }

    public function test_AC11_rollback_removes_audit_and_reverts_paired_change(): void
    {
        $this->pdo->beginTransaction();
        (new UserRepository($this->pdo))->changeRole($this->userId, 'VIEWER');
        (new AuditLogger(new AuditRepository($this->pdo)))->log($this->userId, AuditAction::UPDATE, 'users', $this->userId,
            ['role' => 'ADMIN'], ['role' => 'VIEWER']);
        self::assertCount(1, $this->entries($this->pdo));
        $this->pdo->rollBack();
        self::assertSame([], $this->entries($this->pdo));
        self::assertSame('ADMIN', (new UserRepository($this->pdo))->findById($this->userId)['role']);
    }

    public function test_AC11_audit_failure_allows_caller_to_roll_back_paired_change(): void
    {
        $this->pdo->beginTransaction();
        (new UserRepository($this->pdo))->changeRole($this->userId, 'VIEWER');

        try {
            (new AuditLogger(new AuditRepository($this->pdo)))->log(4294967295, AuditAction::UPDATE, 'users', $this->userId,
                ['role' => 'ADMIN'], ['role' => 'VIEWER']);
            self::fail('Audit accepted a missing actor.');
        } catch (PDOException $error) {
            self::assertSame('23000', $error->getCode());
        } finally {
            $this->pdo->rollBack();
        }

        self::assertSame([], $this->entries($this->pdo));
        self::assertSame('ADMIN', (new UserRepository($this->pdo))->findById($this->userId)['role']);
    }

    public function test_AC11_autocommit_audit_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        (new AuditLogger(new AuditRepository($this->pdo)))->log($this->userId, AuditAction::CREATE, 'children', 7,
            [], ['name' => "FAKE 'quoted child"]);
    }

    public function test_AC11_viewer_filters_combine_and_include_entire_end_date(): void
    {
        $this->pdo->beginTransaction();
        $repository = new AuditRepository($this->pdo);
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity, entity_id, changes, created_at) '
            . 'VALUES (:user_id, :action, :entity, 7, :changes, :created_at)'
        );
        foreach ([['children', '2026-10-01 00:00:00'], ['children', '2026-10-10 23:59:59'],
            ['children', '2026-10-11 00:00:00'], ['visits', '2026-10-10 12:00:00']] as [$entity, $date]) {
            $statement->execute(['user_id' => $this->userId, 'action' => 'CREATE', 'entity' => $entity,
                'changes' => '{"before":{},"after":{"notes":"FAKE"}}', 'created_at' => $date]);
        }
        $filters = ['user_id' => (string) $this->userId, 'entity' => 'children', 'from' => '2026-10-01', 'to' => '2026-10-10'];
        $rows = $repository->search($filters);
        self::assertCount(2, $rows);
        self::assertSame('2026-10-10 23:59:59', $rows[0]['created_at']);
        self::assertSame('FAKE Audit Actor', $rows[0]['user_name']);
        self::assertCount(3, $repository->search(array_replace($filters, ['from' => '', 'to' => ''])));
        self::assertCount(3, $repository->search(array_replace($filters, ['entity' => ''])));
        self::assertSame([], $repository->search(array_replace($filters, ['user_id' => '4294967295'])));
        self::assertSame([], $repository->search(array_replace($filters, ['entity' => "children' OR 1=1"])));
        self::assertContains('children', $repository->entities());
        self::assertContains('visits', $repository->entities());
    }
}
