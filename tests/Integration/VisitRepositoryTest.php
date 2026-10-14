<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Repositories\AuditRepository;
use Pediacare\Repositories\ChildRepository;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Repositories\SupplementRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Repositories\VisitRepository;
use Pediacare\Services\MigrationService;
use Pediacare\Services\VisitService;
use Pediacare\Support\AuditLogger;
use Pediacare\Validation\ChildValidator;
use Pediacare\Validation\VisitValidator;
use PHPUnit\Framework\TestCase;

final class VisitRepositoryTest extends TestCase
{
    private PDO $pdo;
    private int $actor;
    private int $purok;
    private int $child;

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
        $users->insertAdmin('FAKE Visit Staff', $prefix . '@example.invalid', 'FAKE_UNUSED_HASH');
        $this->actor = (int) $users->findByEmail($prefix . '@example.invalid')['id'];
        (new PurokRepository($this->pdo))->insert($prefix . ' Purok');
        $this->purok = (int) $this->pdo->lastInsertId();
        $input = require dirname(__DIR__) . '/Fixtures/child.php';
        $input['purok_id'] = (string) $this->purok;
        $input['date_of_birth'] = '2026-01-01';
        $data = (new ChildValidator())->validate($input, new DateTimeImmutable('2026-10-11'), false);
        $this->child = (new ChildRepository($this->pdo))->insert($data + [
            'record_no' => 'PC-2097-' . str_pad((string) $this->actor, 5, '0', STR_PAD_LEFT), 'created_by' => $this->actor]);
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
        foreach (['DELETE s FROM supplements s JOIN visits v ON v.id = s.visit_id WHERE v.attended_by = :id',
            'DELETE FROM visits WHERE attended_by = :id', 'DELETE FROM measurements WHERE measured_by = :id', 'DELETE FROM audit_logs WHERE user_id = :id',
            'DELETE FROM children WHERE created_by = :id', 'DELETE FROM users WHERE id = :id'] as $sql) {
            $this->pdo->prepare($sql)->execute(['id' => $this->actor]);
        }
        if (isset($this->purok)) {
            $this->pdo->prepare('DELETE FROM puroks WHERE id = :id')->execute(['id' => $this->purok]);
        }
    }

    private function service(?AuditRepository $audit = null, ?SupplementRepository $supplements = null): VisitService
    {
        return new VisitService($this->pdo, new ChildRepository($this->pdo), new VisitRepository($this->pdo),
            $supplements ?? new SupplementRepository($this->pdo), new VisitValidator(),
            new AuditLogger($audit ?? new AuditRepository($this->pdo)));
    }

    public function test_AC03_T33_real_timeline_is_child_scoped_and_includes_all_saved_record_types(): void
    {
        $vaccine = null;
        try {
            $this->service()->record((string) $this->child, $this->input(), $this->actor, new DateTimeImmutable('2026-10-11'));
            $this->pdo->beginTransaction();
            $input = require dirname(__DIR__) . '/Fixtures/child.php';
            $input['purok_id'] = (string) $this->purok;
            $input['date_of_birth'] = '2026-01-01';
            $otherChild = (new ChildRepository($this->pdo))->insert(
                (new ChildValidator())->validate($input, new DateTimeImmutable('2026-10-11'), false) + [
                    'record_no' => 'PC-2096-' . str_pad((string) $this->actor, 5, '0', STR_PAD_LEFT), 'created_by' => $this->actor]);
            // Saved fake results test history retrieval; growth calculation has its own WHO reference tests.
            $measurement = ['child_id' => $this->child, 'measured_at' => '2026-01-01', 'weight_kg' => '6.00',
                'height_cm' => '60.0', 'position' => 'LYING', 'muac_cm' => null, 'age_months' => 0, 'bmi' => '16.67',
                'wfa_z' => '0.11', 'hfa_z' => '0.12', 'wfh_z' => '0.13', 'bmi_z' => '0.14',
                'wfa_status' => 'Normal', 'hfa_status' => 'Normal', 'wfh_status' => 'Normal', 'bmi_status' => 'Normal',
                'implausible_confirmed' => 0, 'measured_by' => $this->actor];
            $measurements = new \Pediacare\Repositories\MeasurementRepository($this->pdo);
            $measurements->insert($measurement);
            $measurements->insert(array_replace($measurement, ['child_id' => $otherChild]));
            $schedule = new \Pediacare\Repositories\VaccineScheduleRepository($this->pdo);
            $vaccine = $schedule->insertVaccine(['code' => 'FAKE' . strtoupper(bin2hex(random_bytes(4))),
                'name' => 'FAKE Timeline Vaccine', 'active' => 0]);
            $dose = $schedule->insertDose(['vaccine_id' => $vaccine, 'dose_number' => 1, 'recommended_age_days' => 0,
                'recommended_age_months' => null, 'grace_days' => 0, 'min_interval_days' => 0,
                'min_interval_months' => null, 'counts_toward_fic' => 1, 'sort_order' => 1]);
            $immunizations = new \Pediacare\Repositories\ImmunizationRepository($this->pdo);
            $record = ['child_id' => $this->child, 'schedule_dose_id' => $dose, 'date_given' => '2026-03-01',
                'lot_number' => null, 'site' => null, 'given_elsewhere' => 1, 'place_given' => 'FAKE Other Facility',
                'given_by' => $this->actor];
            $immunizations->insert($record);
            $immunizations->insert(array_replace($record, ['child_id' => $otherChild]));
            $this->pdo->commit();
            $otherInput = $this->input();
            $otherInput['reason'] = 'FAKE OTHER CHILD ONLY';
            $this->service()->record((string) $otherChild, $otherInput, $this->actor, new DateTimeImmutable('2026-10-11'));
            $timeline = new \Pediacare\Services\HealthTimelineService($measurements, $immunizations,
                new VisitRepository($this->pdo), new SupplementRepository($this->pdo));
            $result = $timeline->forChild($this->child);
            self::assertSame(3, $result['total']);
            self::assertSame(['Vaccination', 'Visit', 'Measurement'], array_column($result['events'], 'type'));
            self::assertSame('FAKE Timeline Vaccine — dose 1', $result['events'][0]['title']);
            self::assertSame('Yes', $result['events'][0]['details']['Given elsewhere']);
            self::assertSame('FAKE Visit Staff', $result['events'][0]['details']['Given by']);
            self::assertSame('FAKE Visit Staff', $result['events'][1]['details']['Attended by']);
            self::assertSame('FAKE Visit Staff', $result['events'][2]['details']['Measured by']);
            self::assertSame('16.67', $result['events'][2]['details']['BMI (kg/m²)']);
            self::assertSame('0.14', $result['events'][2]['details']['BMI-for-age z-score']);
            self::assertCount(4, $result['events'][1]['supplements']);
            self::assertCount(1, (new VisitRepository($this->pdo))->forChild($this->child));
            self::assertCount(4, (new SupplementRepository($this->pdo))->forChild($this->child));
            self::assertSame([], $timeline->forChild(4294967295)['events']);
            self::assertNotContains('FAKE OTHER CHILD ONLY', array_column($result['events'], 'title'));
            self::assertCount(3, $timeline->forChild($otherChild)['events']);
            self::assertFalse($this->pdo->inTransaction());
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->prepare('DELETE FROM immunizations WHERE given_by = :id')->execute(['id' => $this->actor]);
            if ($vaccine !== null) {
                $this->pdo->prepare('DELETE FROM schedule_doses WHERE vaccine_id = :id')->execute(['id' => $vaccine]);
                $this->pdo->prepare('DELETE FROM vaccines WHERE id = :id')->execute(['id' => $vaccine]);
            }
        }
    }

    private function input(bool $withSupplements = true): array
    {
        $input = ['visit_date' => '2026-02-01', 'reason' => "FAKE Visit 'quoted' <script>",
            'findings' => 'FAKE Findings', 'actions' => 'FAKE Actions', 'referred' => '1',
            'referred_to' => 'FAKE Facility', 'notes' => "FAKE Notes\nSecond line"];
        if ($withSupplements) {
            foreach (VisitValidator::SUPPLEMENTS as $type => $label) {
                $input['supplements'][$type] = ['dose' => 'FAKE entered dose ' . $type, 'date_given' => '2026-02-01'];
            }
        }
        return $input;
    }

    private function rows(string $table): array
    {
        $sql = match ($table) {
            'visits' => 'SELECT * FROM visits WHERE child_id = :id ORDER BY id',
            'supplements' => 'SELECT s.* FROM supplements s JOIN visits v ON v.id = s.visit_id WHERE v.child_id = :id ORDER BY s.id',
            'audit_logs' => 'SELECT * FROM audit_logs WHERE user_id = :id ORDER BY id',
        };
        $query = $this->pdo->prepare($sql);
        $query->execute(['id' => $table === 'audit_logs' ? $this->actor : $this->child]);

        return $query->fetchAll();
    }

    public function test_AC11_mysql_stores_visit_all_preventive_services_and_matching_audit_entries(): void
    {
        $input = $this->input() + ['child_id' => '999', 'attended_by' => '999'];
        $id = $this->service()->record((string) $this->child, $input, $this->actor, new DateTimeImmutable('2026-10-11'));
        $visits = $this->rows('visits');
        self::assertCount(1, $visits);
        self::assertSame($id, (int) $visits[0]['id']);
        self::assertSame($this->child, (int) $visits[0]['child_id']);
        self::assertSame($this->actor, (int) $visits[0]['attended_by']);
        foreach (['reason', 'findings', 'actions', 'referred_to', 'notes', 'visit_date'] as $field) {
            self::assertSame($input[$field], $visits[0][$field]);
        }
        self::assertSame(1, (int) $visits[0]['referred']);
        self::assertNotEmpty($visits[0]['created_at']);
        $supplements = $this->rows('supplements');
        self::assertCount(4, $supplements);
        self::assertSame(array_keys(VisitValidator::SUPPLEMENTS), array_column($supplements, 'type'));
        foreach ($supplements as $row) {
            self::assertSame($id, (int) $row['visit_id']);
            self::assertSame($input['supplements'][$row['type']]['dose'], $row['dose']);
            self::assertSame('2026-02-01', $row['date_given']);
        }
        $logs = $this->rows('audit_logs');
        self::assertCount(5, $logs);
        self::assertSame('visits', $logs[0]['entity']);
        self::assertSame($id, (int) $logs[0]['entity_id']);
        $after = json_decode($logs[0]['changes'], true, 512, JSON_THROW_ON_ERROR)['after'];
        self::assertSame($this->actor, $after['attended_by']);
        self::assertSame($input['reason'], $after['reason']);
        foreach ($logs as $index => $log) {
            self::assertSame($this->actor, (int) $log['user_id']);
            self::assertSame('CREATE', $log['action']);
            self::assertNotEmpty($log['created_at']);
            if ($index > 0) {
                self::assertSame('supplements', $log['entity']);
                self::assertSame((int) $supplements[$index - 1]['id'], (int) $log['entity_id']);
                self::assertSame($id, json_decode($log['changes'], true, 512, JSON_THROW_ON_ERROR)['after']['visit_id']);
            }
        }
        self::assertFalse($this->pdo->inTransaction());
    }

    public function test_AC11_visit_without_supplements_saves_one_audit_entry_and_null_optional_fields(): void
    {
        $input = ['visit_date' => '2026-01-01', 'reason' => 'FAKE visit without services', 'referred' => '0'];
        $this->service()->record((string) $this->child, $input, $this->actor, new DateTimeImmutable('2026-10-11'));
        self::assertCount(1, $this->rows('visits'));
        self::assertNull($this->rows('visits')[0]['referred_to']);
        self::assertNull($this->rows('visits')[0]['notes']);
        self::assertSame([], $this->rows('supplements'));
        self::assertCount(1, $this->rows('audit_logs'));
    }

    public function test_AC11_late_audit_failure_rolls_back_visit_supplements_and_previous_audits(): void
    {
        $audit = new class ($this->pdo) extends AuditRepository {
            private int $entries = 0;

            public function insert(int $userId, string $action, string $entity, int $entityId, string $changes, ?string $ipAddress): void
            {
                parent::insert($userId, $action, $entity, $entityId, $changes, $ipAddress);
                if (++$this->entries === 3) {
                    throw new RuntimeException('FAKE late audit failure');
                }
            }
        };
        try {
            $this->service($audit)->record((string) $this->child, $this->input(), $this->actor, new DateTimeImmutable('2026-10-11'));
            self::fail('Expected injected audit failure.');
        } catch (RuntimeException $error) {
            self::assertSame('FAKE late audit failure', $error->getMessage());
        }
        self::assertSame([], $this->rows('visits'));
        self::assertSame([], $this->rows('supplements'));
        self::assertSame([], $this->rows('audit_logs'));
        self::assertFalse($this->pdo->inTransaction());
    }

    public function test_AC11_supplement_write_failure_rolls_back_visit_and_its_audit(): void
    {
        $supplements = new class ($this->pdo) extends SupplementRepository {
            public function insert(array $data): int
            {
                $data['visit_id'] = 4294967295;
                return parent::insert($data);
            }
        };
        try {
            $this->service(null, $supplements)->record((string) $this->child, $this->input(), $this->actor, new DateTimeImmutable('2026-10-11'));
            self::fail('Expected foreign-key failure.');
        } catch (PDOException $error) {
            self::assertSame('23000', $error->getCode());
        }
        self::assertSame([], $this->rows('visits'));
        self::assertSame([], $this->rows('supplements'));
        self::assertSame([], $this->rows('audit_logs'));
    }

    public function test_AC12_archived_child_cannot_receive_a_visit_or_supplements(): void
    {
        $this->pdo->prepare('UPDATE children SET archived_at = CURRENT_TIMESTAMP, archive_reason = :reason WHERE id = :id')
            ->execute(['reason' => 'FAKE archived', 'id' => $this->child]);
        try {
            $this->service()->record((string) $this->child, $this->input(), $this->actor, new DateTimeImmutable('2026-10-11'));
            self::fail('Expected archived child rejection.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('Restore', $error->getMessage());
        }
        self::assertSame([], $this->rows('visits'));
        self::assertSame([], $this->rows('supplements'));
        self::assertSame([], $this->rows('audit_logs'));
    }
}
