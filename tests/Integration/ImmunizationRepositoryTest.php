<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Repositories\AuditRepository;
use Pediacare\Repositories\ChildRepository;
use Pediacare\Repositories\ImmunizationRepository;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Repositories\VaccineScheduleRepository;
use Pediacare\Services\ImmunizationService;
use Pediacare\Services\MigrationService;
use Pediacare\Support\AuditLogger;
use Pediacare\Validation\ChildValidator;
use Pediacare\Validation\ImmunizationValidator;
use PHPUnit\Framework\TestCase;

final class ImmunizationRepositoryTest extends TestCase
{
    private PDO $pdo;
    private int $actor;
    private int $purok;
    private int $child;
    private int $vaccine;
    private int $first;
    private int $second;

    protected function setUp(): void
    {
        $path = getenv('PEDIACARE_TEST_CONFIG') ?: dirname(__DIR__, 2) . '/config/config.local.php';
        if (!is_file($path)) {
            self::markTestSkipped('Configure pediacare_test to run MySQL integration tests.');
        }
        $config = (require $path)['database'];
        self::assertSame('pediacare_test', $config['test_name']);
        self::assertNotSame($config['name'], $config['test_name']);
        $config['name'] = $config['test_name'];
        $this->pdo = Database::connect($config);
        (new MigrationService(new MigrationRepository($this->pdo)))->run(dirname(__DIR__, 2) . '/database/migrations');
        $prefix = 'FAKE' . strtoupper(bin2hex(random_bytes(4)));
        $this->pdo->beginTransaction();
        $users = new UserRepository($this->pdo);
        $users->insertAdmin('FAKE Vaccine Staff', $prefix . '@example.invalid', 'FAKE_UNUSED_HASH');
        $this->actor = (int) $users->findByEmail($prefix . '@example.invalid')['id'];
        (new PurokRepository($this->pdo))->insert($prefix . ' Purok');
        $this->purok = (int) $this->pdo->lastInsertId();
        $input = require dirname(__DIR__) . '/Fixtures/child.php';
        $input['purok_id'] = (string) $this->purok;
        $input['date_of_birth'] = '2026-01-01';
        $data = (new ChildValidator())->validate($input, new DateTimeImmutable('2026-10-10'), false);
        $this->child = (new ChildRepository($this->pdo))->insert($data + [
            'record_no' => 'PC-2099-' . str_pad((string) $this->actor, 5, '0', STR_PAD_LEFT), 'created_by' => $this->actor]);
        $repo = new VaccineScheduleRepository($this->pdo);
        $this->vaccine = $repo->insertVaccine(['code' => $prefix, 'name' => 'FAKE Vaccine', 'active' => 1]);
        $dose = ['vaccine_id' => $this->vaccine, 'dose_number' => 1, 'recommended_age_days' => 0,
            'recommended_age_months' => null, 'grace_days' => 0, 'min_interval_days' => 0,
            'min_interval_months' => null, 'counts_toward_fic' => 1, 'sort_order' => 1];
        $this->first = $repo->insertDose($dose);
        $this->second = $repo->insertDose(array_replace($dose, ['dose_number' => 2, 'recommended_age_days' => 42,
            'min_interval_days' => 28, 'sort_order' => 2]));
        $this->pdo->commit();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (!isset($this->actor)) {
            return;
        }
        foreach (['DELETE FROM immunizations WHERE given_by = :id', 'DELETE FROM audit_logs WHERE user_id = :id',
            'DELETE FROM children WHERE created_by = :id', 'DELETE FROM users WHERE id = :id'] as $sql) {
            $this->pdo->prepare($sql)->execute(['id' => $this->actor]);
        }
        if (isset($this->vaccine)) {
            $this->pdo->prepare('DELETE FROM schedule_doses WHERE vaccine_id = :id')->execute(['id' => $this->vaccine]);
            $this->pdo->prepare('DELETE FROM vaccines WHERE id = :id')->execute(['id' => $this->vaccine]);
        }
        if (isset($this->purok)) {
            $this->pdo->prepare('DELETE FROM puroks WHERE id = :id')->execute(['id' => $this->purok]);
        }
    }

    private function service(?AuditRepository $audit = null): ImmunizationService
    {
        return new ImmunizationService($this->pdo, new ChildRepository($this->pdo), new VaccineScheduleRepository($this->pdo),
            new ImmunizationRepository($this->pdo), new ImmunizationValidator(), new AuditLogger($audit ?? new AuditRepository($this->pdo)));
    }

    private function input(int $dose, string $date): array
    {
        return ['schedule_dose_id' => (string) $dose, 'date_given' => $date, 'given_elsewhere' => '1',
            'place_given' => 'FAKE Other Facility', 'lot_number' => '', 'site' => 'FAKE Site'];
    }

    private function insertFakeChild(int $sequence, string $birth): int
    {
        $input = require dirname(__DIR__) . '/Fixtures/child.php';
        $input['purok_id'] = (string) $this->purok;
        $input['date_of_birth'] = $birth;
        $input['first_name'] = 'FAKE Cohort ' . $sequence;
        $data = (new ChildValidator())->validate($input, new DateTimeImmutable('2026-10-10'), false);

        return (new ChildRepository($this->pdo))->insert($data + ['record_no' => 'PC-2098-' . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            'created_by' => $this->actor]);
    }

    public function test_AC09_AC12_defaulters_exclude_archived_given_due_and_upcoming_in_real_database(): void
    {
        $this->pdo->beginTransaction();
        $archived = $this->insertFakeChild(1, '2026-01-01');
        $newborn = $this->insertFakeChild(2, '2026-03-01');
        $given = $this->insertFakeChild(3, '2026-01-01');
        $due = $this->insertFakeChild(4, '2026-01-18');
        $this->pdo->prepare('UPDATE children SET archived_at = :date, archive_reason = :reason WHERE id = :id')
            ->execute(['date' => '2026-02-01', 'reason' => 'FAKE Archive Fixture', 'id' => $archived]);
        $this->pdo->commit();
        $service = $this->service();
        $today = new DateTimeImmutable('2026-03-01');
        $service->record((string) $given, $this->input($this->first, '2026-01-01'), $this->actor, $today);
        $service->record((string) $given, $this->input($this->second, '2026-02-12'), $this->actor, $today);
        $service->record((string) $due, $this->input($this->first, '2026-01-18'), $this->actor, $today);
        $filters = ['vaccine_id' => (string) $this->vaccine, 'purok_id' => (string) $this->purok, 'include_archived' => '1'];
        $result = $service->defaulters($filters, $today);
        self::assertSame([$this->child], array_column($result['children'], 'id'));
        self::assertCount(2, $result['children'][0]['overdue']);
        self::assertSame(1, $result['total']);
        self::assertSame(0, $service->defaulters(array_replace($filters, ['purok_id' => '4294967295']), $today)['total']);
        $records = (new ImmunizationRepository($this->pdo))->forChildren([$this->child, $given, $due]);
        self::assertArrayNotHasKey($this->child, $records);
        self::assertCount(2, $records[$given]);
        self::assertCount(1, $records[$due]);
        self::assertSame([], (new ImmunizationRepository($this->pdo))->forChildren([]));
        $batch = (new ChildRepository($this->pdo))->immunizationBatch(0, $this->purok);
        self::assertNotContains($archived, array_column($batch, 'id'));
        self::assertContains($newborn, array_column($batch, 'id'));
    }

    public function test_AC09_real_keyset_batches_find_every_defaulter_without_duplicates(): void
    {
        $this->pdo->beginTransaction();
        $expected = [$this->child];
        for ($i = 1; $i <= 201; ++$i) {
            $expected[] = $this->insertFakeChild($i, '2026-01-01');
        }
        $this->pdo->commit();
        $repo = new ChildRepository($this->pdo);
        $first = $repo->immunizationBatch(0, $this->purok);
        self::assertCount(200, $first);
        $second = $repo->immunizationBatch((int) $first[199]['id'], $this->purok);
        self::assertCount(2, $second);
        self::assertSame($expected, array_merge(array_column($first, 'id'), array_column($second, 'id')));
        $filters = ['purok_id' => (string) $this->purok, 'vaccine_id' => (string) $this->vaccine];
        $found = [];
        for ($page = 1; $page <= 9; ++$page) {
            $result = $this->service()->defaulters($filters + ['page' => (string) $page], new DateTimeImmutable('2026-03-01'));
            self::assertSame(202, $result['total']);
            self::assertSame(9, $result['pages']);
            $found = [...$found, ...array_column($result['children'], 'id')];
        }
        self::assertSame($expected, $found);
        $all = $this->service()->defaulters($filters + ['page' => '9'], new DateTimeImmutable('2026-03-01'), true);
        self::assertSame($expected, array_column($all['children'], 'id'));
        self::assertSame(1, $all['pages']);
        self::assertSame(203, count(explode("\r\n", rtrim(\Pediacare\Support\ReportOutput::csv('defaulters', $all)->body))));
        $last = $this->service()->defaulters($filters + ['page' => '999'], new DateTimeImmutable('2026-03-01'));
        self::assertSame(9, $last['page']);
        self::assertCount(2, $last['children']);
    }

    public function test_AC07_AC08_AC11_real_records_store_elsewhere_details_audit_changes_and_complete_fic(): void
    {
        $service = $this->service();
        $today = new DateTimeImmutable('2026-10-10');
        $service->record((string) $this->child, $this->input($this->first, '2026-01-01'), $this->actor, $today);
        $service->record((string) $this->child, $this->input($this->second, '2026-02-12'), $this->actor, $today);
        $records = (new ImmunizationRepository($this->pdo))->forChild($this->child);
        self::assertCount(2, $records);
        self::assertSame(1, (int) $records[0]['given_elsewhere']);
        self::assertSame('FAKE Other Facility', $records[0]['place_given']);
        self::assertSame('FAKE Site', $records[0]['site']);
        self::assertNull($records[0]['lot_number']);
        self::assertSame($this->actor, (int) $records[0]['given_by']);
        self::assertSame('FAKE Vaccine Staff', $records[0]['recorded_by']);
        self::assertNotEmpty($records[0]['created_at']);
        $page = $service->forChild(['id' => $this->child, 'date_of_birth' => '2026-01-01'], $today);
        self::assertTrue($page['fic']);
        self::assertSame(0, $page['available']);
        self::assertSame(['Given', 'Given'], array_column($page['vaccines'][0]['doses'], 'status'));
        $audits = $this->pdo->prepare('SELECT * FROM audit_logs WHERE user_id = :id ORDER BY id');
        $audits->execute(['id' => $this->actor]);
        $logs = $audits->fetchAll();
        self::assertCount(2, $logs);
        self::assertSame('immunizations', $logs[0]['entity']);
        $changes = json_decode($logs[0]['changes'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->child, $changes['after']['child_id']);
        self::assertSame(1, $changes['after']['given_elsewhere']);
        self::assertSame('FAKE Other Facility', $changes['after']['place_given']);
        try {
            $service->record((string) $this->child, $this->input($this->first, '2026-01-01'), $this->actor, $today);
            self::fail('Expected duplicate rejection.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('already recorded', $error->getMessage());
        }
        self::assertCount(2, (new ImmunizationRepository($this->pdo))->forChild($this->child));
        $audits->execute(['id' => $this->actor]);
        self::assertCount(2, $audits->fetchAll());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function test_AC11_real_insert_and_audit_both_roll_back_on_audit_failure(): void
    {
        $failing = new class ($this->pdo) extends AuditRepository {
            public function insert(int $userId, string $action, string $entity, int $entityId, string $changes, ?string $ipAddress): void
            {
                parent::insert($userId, $action, $entity, $entityId, $changes, $ipAddress);
                throw new RuntimeException('FAKE audit failure');
            }
        };
        try {
            $this->service($failing)->record((string) $this->child, $this->input($this->first, '2026-01-01'), $this->actor,
                new DateTimeImmutable('2026-10-10'));
            self::fail('Expected injected failure.');
        } catch (RuntimeException $error) {
            self::assertSame('FAKE audit failure', $error->getMessage());
        }
        self::assertSame([], (new ImmunizationRepository($this->pdo))->forChild($this->child));
        $audits = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE user_id = :id');
        $audits->execute(['id' => $this->actor]);
        self::assertSame(0, (int) $audits->fetchColumn());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function test_AC07_interval_rejection_does_not_change_overdue_status_or_save_record(): void
    {
        $service = $this->service();
        $today = new DateTimeImmutable('2026-10-10');
        $service->record((string) $this->child, $this->input($this->first, '2026-03-01'), $this->actor, $today);
        try {
            $service->record((string) $this->child, $this->input($this->second, '2026-03-28'), $this->actor, $today);
            self::fail('Expected minimum interval rejection.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('2026-03-29', $error->getMessage());
        }
        $page = $service->forChild(['id' => $this->child, 'date_of_birth' => '2026-01-01'], $today);
        self::assertFalse($page['fic']);
        self::assertSame('Overdue', $page['vaccines'][0]['doses'][1]['status']);
        self::assertSame('2026-03-29', $page['vaccines'][0]['doses'][1]['earliest_date']);
        self::assertCount(1, (new ImmunizationRepository($this->pdo))->forChild($this->child));
        $service->record((string) $this->child, $this->input($this->second, '2026-03-29'), $this->actor, $today);
        self::assertTrue($service->forChild(['id' => $this->child, 'date_of_birth' => '2026-01-01'], $today)['fic']);
    }
}
