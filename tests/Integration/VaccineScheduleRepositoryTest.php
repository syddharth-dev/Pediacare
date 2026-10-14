<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Repositories\VaccineScheduleRepository;
use Pediacare\Services\MigrationService;
use Pediacare\Services\VaccineScheduleSeedService;
use PHPUnit\Framework\TestCase;

final class VaccineScheduleRepositoryTest extends TestCase
{
    private PDO $pdo;
    private array $seed;
    private string $prefix;
    private ?int $actor = null;

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
        $this->prefix = 'FAKE' . strtoupper(bin2hex(random_bytes(4)));
        $this->seed = require dirname(__DIR__, 2) . '/database/seeds/vaccine-schedule.php';
        foreach ($this->seed as &$vaccine) {
            $vaccine['code'] = $this->prefix . $vaccine['code'];
        }
        unset($vaccine);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if ($this->actor !== null) {
            $this->pdo->prepare('DELETE FROM audit_logs WHERE user_id = :id')->execute(['id' => $this->actor]);
        }
        foreach (['DELETE d FROM schedule_doses d JOIN vaccines v ON v.id = d.vaccine_id WHERE v.code LIKE :prefix',
            'DELETE FROM vaccines WHERE code LIKE :prefix'] as $sql) {
            $this->pdo->prepare($sql)->execute(['prefix' => $this->prefix . '%']);
        }
        if ($this->actor !== null) {
            $this->pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $this->actor]);
        }
    }

    private function rows(): array
    {
        $statement = $this->pdo->prepare('SELECT v.code, v.name, v.active, d.* FROM vaccines v
            JOIN schedule_doses d ON d.vaccine_id = v.id WHERE v.code LIKE :prefix ORDER BY d.sort_order');
        $statement->execute(['prefix' => $this->prefix . '%']);

        return $statement->fetchAll();
    }

    public function test_AC02_AC07_AC08_editor_persists_units_flags_and_audits_and_rolls_back_failed_writes(): void
    {
        $users = new \Pediacare\Repositories\UserRepository($this->pdo);
        $email = $this->prefix . '@example.invalid';
        $users->insertAdmin('FAKE Schedule Admin', $email, 'FAKE_UNUSED_HASH');
        $this->actor = (int) $users->findByEmail($email)['id'];
        $repo = new VaccineScheduleRepository($this->pdo);
        $auditRepo = new \Pediacare\Repositories\AuditRepository($this->pdo);
        $service = new \Pediacare\Services\VaccineScheduleService($this->pdo, $repo,
            new \Pediacare\Validation\VaccineScheduleValidator(), new \Pediacare\Support\AuditLogger($auditRepo));
        $id = $service->saveVaccine(null, ['code' => $this->prefix . 'EXTRA', 'name' => 'FAKE Vaccine', 'active' => '1'], $this->actor);
        $service->saveVaccine((string) $id, ['code' => 'INJECTED', 'name' => 'FAKE Renamed', 'active' => '0'], $this->actor);
        self::assertSame($this->prefix . 'EXTRA', $repo->vaccine($id)['code']);
        self::assertSame('FAKE Renamed', $repo->vaccine($id)['name']);
        self::assertSame(0, (int) $repo->vaccine($id)['active']);
        $input = ['dose_number' => '1', 'age_value' => '42', 'age_unit' => 'days', 'grace_days' => '0',
            'interval_value' => '0', 'interval_unit' => 'days', 'counts_toward_fic' => '0', 'sort_order' => '1'];
        $doseId = $service->saveDose(null, (string) $id, $input, $this->actor);
        $secondId = $service->saveDose(null, (string) $id, array_replace($input, ['dose_number' => '2']), $this->actor);
        self::assertFalse($repo->doseUsed($doseId));
        $service->saveDose((string) $doseId, null, array_replace($input, ['age_value' => '9', 'age_unit' => 'months',
            'grace_days' => '7', 'interval_value' => '4', 'interval_unit' => 'months', 'counts_toward_fic' => '1']), $this->actor);
        $saved = $repo->dose($doseId);
        self::assertNull($saved['recommended_age_days']);
        self::assertSame(9, (int) $saved['recommended_age_months']);
        self::assertNull($saved['min_interval_days']);
        self::assertSame(4, (int) $saved['min_interval_months']);
        self::assertSame(7, (int) $saved['grace_days']);
        self::assertSame(1, (int) $saved['counts_toward_fic']);
        $all = array_values(array_filter($service->all(), fn (array $v): bool => $v['id'] === $id));
        self::assertCount(1, $all);
        self::assertCount(2, $all[0]['doses']);
        $audits = $this->pdo->prepare('SELECT * FROM audit_logs WHERE user_id = :id ORDER BY id');
        $audits->execute(['id' => $this->actor]);
        $logs = $audits->fetchAll();
        self::assertCount(5, $logs);
        self::assertSame(['CREATE', 'UPDATE', 'CREATE', 'CREATE', 'UPDATE'], array_column($logs, 'action'));
        $changes = json_decode($logs[4]['changes'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(42, $changes['before']['recommended_age_days']);
        self::assertNull($changes['after']['recommended_age_days']);
        self::assertSame(9, $changes['after']['recommended_age_months']);
        self::assertArrayNotHasKey('dose_number', $changes['after']);
        foreach ([fn (): int => $service->saveVaccine(null,
            ['code' => $repo->vaccine($id)['code'], 'name' => 'FAKE Duplicate', 'active' => '1'], $this->actor),
            fn (): int => $service->saveDose((string) $secondId, null, $input, $this->actor)] as $duplicate) {
            try {
                $duplicate();
                self::fail('Expected a duplicate validation error.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('already exists', $error->getMessage());
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame(2, (int) $repo->dose($secondId)['dose_number']);
        $failingAudit = new class ($this->pdo) extends \Pediacare\Repositories\AuditRepository {
            public function insert(int $userId, string $action, string $entity, int $entityId, string $changes, ?string $ipAddress): void
            {
                parent::insert($userId, $action, $entity, $entityId, $changes, $ipAddress);
                throw new RuntimeException('FAKE audit failure after insert');
            }
        };
        $failingService = new \Pediacare\Services\VaccineScheduleService($this->pdo, $repo,
            new \Pediacare\Validation\VaccineScheduleValidator(), new \Pediacare\Support\AuditLogger($failingAudit));
        try {
            $failingService->saveVaccine((string) $id, ['name' => 'FAKE Should Roll Back', 'active' => '1'], $this->actor);
            self::fail('Expected an audit failure.');
        } catch (RuntimeException $error) {
            self::assertSame('FAKE audit failure after insert', $error->getMessage());
        }
        self::assertSame('FAKE Renamed', $repo->vaccine($id)['name']);
        $audits->execute(['id' => $this->actor]);
        self::assertCount(5, $audits->fetchAll());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function test_AC07_AC08_seed_is_repeatable_preserves_admin_edits_and_stores_exact_units(): void
    {
        $service = new VaccineScheduleSeedService($this->pdo, new VaccineScheduleRepository($this->pdo));
        self::assertSame(15, $service->run($this->seed));
        $rows = $this->rows();
        self::assertCount(15, $rows);
        self::assertCount(7, array_unique(array_column($rows, 'vaccine_id')));
        self::assertSame(9, array_sum(array_column($rows, 'counts_toward_fic')));
        $index = 0;
        foreach ($this->seed as $vaccine) {
            foreach ($vaccine['doses'] as $number => $expected) {
                $row = $rows[$index++];
                self::assertSame($vaccine['code'], $row['code']);
                self::assertSame($number + 1, (int) $row['dose_number']);
                foreach (['recommended_age_days', 'recommended_age_months', 'min_interval_days', 'min_interval_months', 'counts_toward_fic'] as $fieldIndex => $field) {
                    self::assertSame($expected[$fieldIndex], $row[$field] === null ? null : (int) $row[$field]);
                }
                self::assertSame(0, (int) $row['grace_days']);
            }
        }
        $this->pdo->prepare('UPDATE vaccines SET name = :name, active = 0 WHERE id = :id')
            ->execute(['name' => 'FAKE Admin rename', 'id' => $rows[0]['vaccine_id']]);
        $this->pdo->prepare('UPDATE schedule_doses SET grace_days = 7, counts_toward_fic = 0 WHERE id = :id')
            ->execute(['id' => $rows[0]['id']]);
        $edited = $this->rows();
        self::assertSame(15, $service->run($this->seed));
        self::assertSame($edited, $this->rows());
        self::assertSame([], (new MigrationService(new MigrationRepository($this->pdo)))->run(dirname(__DIR__, 2) . '/database/migrations'));
    }

    public function test_AC07_partial_seed_failure_rolls_back_real_database_inserts(): void
    {
        $repository = new class ($this->pdo) extends VaccineScheduleRepository {
            public function ensureDose(array $data): void
            {
                parent::ensureDose($data);
                throw new RuntimeException('FAKE failure after real insert');
            }
        };
        try {
            (new VaccineScheduleSeedService($this->pdo, $repository))->run($this->seed);
            self::fail('Expected injected seed failure.');
        } catch (RuntimeException $error) {
            self::assertSame('FAKE failure after real insert', $error->getMessage());
        }
        self::assertSame([], $this->rows());
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM vaccines WHERE code LIKE :prefix');
        $statement->execute(['prefix' => $this->prefix . '%']);
        self::assertSame(0, (int) $statement->fetchColumn());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function test_AC11_immunizations_preserve_attribution_elsewhere_details_and_reject_duplicate_or_orphan_records(): void
    {
        (new VaccineScheduleSeedService($this->pdo, new VaccineScheduleRepository($this->pdo)))->run($this->seed);
        $doseId = $this->rows()[0]['id'];
        $this->pdo->beginTransaction();
        try {
            $users = new \Pediacare\Repositories\UserRepository($this->pdo);
            $email = $this->prefix . '@example.invalid';
            $users->insertAdmin('FAKE Vaccine Actor', $email, 'FAKE_UNUSED_HASH');
            $userId = (int) $users->findByEmail($email)['id'];
            (new \Pediacare\Repositories\PurokRepository($this->pdo))->insert($this->prefix . 'Purok');
            $purokId = (int) $this->pdo->lastInsertId();
            $input = require dirname(__DIR__) . '/Fixtures/child.php';
            $input['purok_id'] = (string) $purokId;
            $data = (new \Pediacare\Validation\ChildValidator())->validate($input, null, false);
            $childId = (new \Pediacare\Repositories\ChildRepository($this->pdo))->insert(
                $data + ['record_no' => 'PC-2099-' . str_pad((string) $userId, 5, '0', STR_PAD_LEFT), 'created_by' => $userId]
            );
            $values = ['child_id' => $childId, 'schedule_dose_id' => $doseId, 'date_given' => '2026-01-01',
                'lot_number' => null, 'site' => null, 'given_elsewhere' => 1,
                'place_given' => 'FAKE Other Center', 'given_by' => $userId];
            $insert = $this->pdo->prepare('INSERT INTO immunizations (child_id, schedule_dose_id, date_given,
                lot_number, site, given_elsewhere, place_given, given_by) VALUES (:child_id, :schedule_dose_id,
                :date_given, :lot_number, :site, :given_elsewhere, :place_given, :given_by)');
            $insert->execute($values);
            $id = (int) $this->pdo->lastInsertId();
            self::assertTrue((new VaccineScheduleRepository($this->pdo))->doseUsed((int) $doseId));
            $select = $this->pdo->prepare('SELECT * FROM immunizations WHERE id = :id');
            $select->execute(['id' => $id]);
            $row = $select->fetch();
            self::assertSame($userId, (int) $row['given_by']);
            self::assertSame(1, (int) $row['given_elsewhere']);
            self::assertSame('FAKE Other Center', $row['place_given']);
            self::assertNull($row['lot_number']);
            self::assertNull($row['site']);
            self::assertNotEmpty($row['created_at']);
            foreach ([[], ['child_id' => 0], ['schedule_dose_id' => 0], ['given_by' => 0], ['given_elsewhere' => 2]] as $changes) {
                try {
                    $insert->execute(array_replace($values, $changes));
                    self::fail('Expected duplicate, foreign-key or flag rejection.');
                } catch (PDOException $error) {
                    self::assertNotEmpty($error->getCode());
                }
            }
            foreach (['children' => $childId, 'schedule_doses' => $doseId, 'users' => $userId] as $table => $targetId) {
                try {
                    $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE id = :id')->execute(['id' => $targetId]);
                    self::fail('Expected health record foreign-key protection.');
                } catch (PDOException $error) {
                    self::assertSame('23000', $error->getCode());
                }
            }
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function test_AC07_schedule_constraints_reject_ambiguous_units_invalid_flags_and_duplicate_doses(): void
    {
        (new VaccineScheduleSeedService($this->pdo, new VaccineScheduleRepository($this->pdo)))->run($this->seed);
        $id = $this->rows()[0]['id'];
        foreach (['recommended_age_months = 9', 'recommended_age_days = NULL', 'min_interval_months = 4',
            'min_interval_days = NULL', 'dose_number = 0', 'counts_toward_fic = 2', 'vaccine_id = 0'] as $change) {
            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare('UPDATE schedule_doses SET ' . $change . ' WHERE id = :id')->execute(['id' => $id]);
                self::fail('Expected constraint rejection.');
            } catch (PDOException $error) {
                self::assertNotEmpty($error->getCode());
            } finally {
                $this->pdo->rollBack();
            }
        }
        try {
            $this->pdo->prepare('INSERT INTO schedule_doses (vaccine_id, dose_number, recommended_age_days, grace_days,
                min_interval_days, counts_toward_fic, sort_order) SELECT vaccine_id, dose_number, recommended_age_days,
                grace_days, min_interval_days, counts_toward_fic, sort_order FROM schedule_doses WHERE id = :id')
                ->execute(['id' => $id]);
            self::fail('Expected duplicate dose rejection.');
        } catch (PDOException $error) {
            self::assertSame('23000', $error->getCode());
        }
    }
}
