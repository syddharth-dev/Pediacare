<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Domain\Growth\Classifier;
use Pediacare\Domain\Growth\GrowthResult;
use Pediacare\Repositories\AuditRepository;
use Pediacare\Repositories\ChildRepository;
use Pediacare\Repositories\MeasurementRepository;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Repositories\WhoLmsRepository;
use Pediacare\Services\MeasurementService;
use Pediacare\Services\MigrationService;
use Pediacare\Services\WhoLmsSeedService;
use Pediacare\Support\AuditLogger;
use Pediacare\Support\MeasurementConfirmation;
use Pediacare\Validation\ChildValidator;
use Pediacare\Validation\MeasurementValidator;
use PHPUnit\Framework\TestCase;

final class MeasurementRepositoryTest extends TestCase
{
    private static bool $seeded = false;
    private PDO $pdo;
    private int $userId;
    private int $childId;
    private int $purokId;
    private array $confirmationSession = [];

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
        if (!self::$seeded) {
            (new WhoLmsSeedService($this->pdo, new WhoLmsRepository($this->pdo)))->run(dirname(__DIR__, 2) . '/database/who');
            self::$seeded = true;
        }
        $email = 'fake-measurement-' . bin2hex(random_bytes(8)) . '@example.invalid';
        $users = new UserRepository($this->pdo);
        $users->insertAdmin('FAKE Measurement Actor', $email, 'FAKE_UNUSED_HASH');
        $this->userId = (int) $users->findByEmail($email)['id'];
        (new PurokRepository($this->pdo))->insert('FAKE Measurement Purok ' . bin2hex(random_bytes(8)));
        $this->purokId = (int) $this->pdo->lastInsertId();
        $input = require dirname(__DIR__) . '/Fixtures/child.php';
        $input['purok_id'] = (string) $this->purokId;
        $input['date_of_birth'] = '2000-01-01';
        $input['sex'] = 'M';
        $data = (new ChildValidator())->validate($input, null, false);
        $data += ['record_no' => 'PC-2099-' . str_pad((string) $this->userId, 5, '0', STR_PAD_LEFT), 'created_by' => $this->userId];
        $this->pdo->beginTransaction();
        $this->childId = (new ChildRepository($this->pdo))->insert($data);
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
        if (isset($this->userId)) {
            foreach (['DELETE FROM measurements WHERE measured_by = :id', 'DELETE FROM audit_logs WHERE user_id = :id',
                'DELETE FROM children WHERE created_by = :id', 'DELETE FROM users WHERE id = :id'] as $sql) {
                $this->pdo->prepare($sql)->execute(['id' => $this->userId]);
            }
        }
        if (isset($this->purokId)) {
            $this->pdo->prepare('DELETE FROM puroks WHERE id = :id')->execute(['id' => $this->purokId]);
        }
    }

    private function service(?AuditRepository $audit = null): MeasurementService
    {
        return new MeasurementService($this->pdo, new ChildRepository($this->pdo), new MeasurementRepository($this->pdo),
            new WhoLmsRepository($this->pdo), new MeasurementValidator(), new AuditLogger($audit ?? new AuditRepository($this->pdo)),
            new MeasurementConfirmation($this->confirmationSession));
    }

    private function input(): array
    {
        return ['measured_at' => '2000-12-31', 'weight_kg' => '9.5', 'height_cm' => '75.4', 'muac_cm' => '12.3', 'position' => 'LYING'];
    }

    public function test_AC06_AC12_history_is_child_scoped_newest_first_and_preserved_when_archived(): void
    {
        $service = $this->service();
        $first = $service->record((string) $this->childId, $this->input(), $this->userId);
        $older = $service->record((string) $this->childId,
            array_replace($this->input(), ['measured_at' => '2000-12-30']), $this->userId);
        $latest = $service->record((string) $this->childId, $this->input(), $this->userId);
        $repository = new MeasurementRepository($this->pdo);
        $history = $repository->history($this->childId);
        self::assertSame([$latest->id, $first->id, $older->id], array_map('intval', array_column($history, 'id')));
        self::assertSame('FAKE Measurement Actor', $history[0]['measured_by_name']);
        self::assertSame('16.71', $history[0]['bmi']);
        self::assertSame('Normal', $history[0]['bmi_status']);
        self::assertSame([], $repository->history(0));
        $input = require dirname(__DIR__) . '/Fixtures/child.php';
        $input['purok_id'] = (string) $this->purokId;
        $input['date_of_birth'] = '2000-01-01';
        $input['sex'] = 'M';
        $data = (new ChildValidator())->validate($input, null, false);
        $data += ['record_no' => 'FAKE-T26-' . $this->userId, 'created_by' => $this->userId];
        $this->pdo->beginTransaction();
        $otherChild = (new ChildRepository($this->pdo))->insert($data);
        $this->pdo->commit();
        $service->record((string) $otherChild, $this->input(), $this->userId);
        self::assertSame($history, $repository->history($this->childId));
        self::assertCount(1, $repository->history($otherChild));
        $this->pdo->prepare('UPDATE children SET archived_at = NOW(), archive_reason = :reason WHERE id = :id')
            ->execute(['reason' => 'FAKE archive test', 'id' => $this->childId]);
        self::assertSame($history, $repository->history($this->childId));
        $growth = (new \Pediacare\Services\GrowthHistoryService($repository, new WhoLmsRepository($this->pdo)))
            ->forChild(['id' => $this->childId, 'date_of_birth' => '2000-01-01', 'sex' => 'M']);
        self::assertSame($history, $growth['history']);
        self::assertCount(3, json_decode($growth['chartJson'], true, 512, JSON_THROW_ON_ERROR)['points']);
    }

    private function rows(string $table): array
    {
        $sql = match ($table) {
            'measurements' => 'SELECT * FROM measurements WHERE measured_by = :id ORDER BY id',
            'audit' => 'SELECT * FROM audit_logs WHERE user_id = :id ORDER BY id',
        };
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $this->userId]);

        return $statement->fetchAll();
    }

    public function test_AC06_AC11_all_24_WHO_fixtures_persist_expected_BMI_scores_statuses_and_actor_with_audit(): void
    {
        $cases = json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/who-anthro-growth.json'), true, 512, JSON_THROW_ON_ERROR);
        $service = $this->service();
        $birth = new DateTimeImmutable('2000-01-01');
        foreach ($cases as $case) {
            $this->pdo->prepare('UPDATE children SET sex = :sex WHERE id = :id')->execute(['sex' => $case['sex'], 'id' => $this->childId]);
            $input = ['measured_at' => $birth->modify('+' . $case['days'] . ' days')->format('Y-m-d'),
                'weight_kg' => number_format($case['weight_kg'], 2, '.', ''), 'height_cm' => number_format($case['height_cm'], 1, '.', ''),
                'muac_cm' => '', 'position' => $case['position'], 'bmi' => 'FAKE_FORGED_VALUE', 'measured_by' => '999'];
            $result = $service->record((string) $this->childId, $input, $this->userId);
            if ($result->id === null) {
                $result = $service->record((string) $this->childId, $input + ['confirm_implausible' => '1', 'confirmation_token' => $result->confirmationToken], $this->userId);
            }
            self::assertNotNull($result->id, $case['id']);
            $statement = $this->pdo->prepare('SELECT * FROM measurements WHERE id = :id');
            $statement->execute(['id' => $result->id]);
            $stored = $statement->fetch();
            self::assertEqualsWithDelta($case['bmi'], (float) $stored['bmi'], 0.01);
            foreach (['wfa_z', 'hfa_z', 'wfh_z', 'bmi_z'] as $indicator) {
                if ($case[$indicator] === null) {
                    self::assertNull($stored[$indicator]);
                } else {
                    self::assertEqualsWithDelta($case[$indicator], (float) $stored[$indicator], 0.01, $case['id'] . ':' . $indicator);
                }
            }
            $expected = Classifier::classify(new GrowthResult($case['reference'], $case['bmi'], $case['adjusted_height_cm'],
                $case['wfa_z'], $case['hfa_z'], $case['wfh_z'], $case['bmi_z']));
            foreach (['wfa', 'hfa', 'wfh', 'bmi'] as $indicator) {
                $property = $indicator . 'Status';
                self::assertSame($expected->$property?->value, $stored[$indicator . '_status']);
            }
            self::assertSame($expected->needsConfirmation() ? 1 : 0, (int) $stored['implausible_confirmed']);
            self::assertSame($this->userId, (int) $stored['measured_by']);
            self::assertSame($input['height_cm'], $stored['height_cm']);
            $audit = array_slice($this->rows('audit'), -1)[0];
            self::assertSame('measurements', $audit['entity']);
            self::assertSame($result->id, (int) $audit['entity_id']);
            self::assertSame('CREATE', $audit['action']);
            self::assertNotEmpty($audit['created_at']);
            $changes = json_decode($audit['changes'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([], $changes['before']);
            self::assertSame($stored['bmi'], $changes['after']['bmi']);
            self::assertArrayNotHasKey('confirmation_token', $changes['after']);
        }
        self::assertCount(24, $this->rows('measurements'));
        self::assertCount(24, $this->rows('audit'));
    }

    public function test_AC14_warning_writes_nothing_and_checkbox_alone_cannot_bypass_confirmation(): void
    {
        $input = array_replace($this->input(), ['weight_kg' => '20']);
        $service = $this->service();
        $first = $service->record((string) $this->childId, $input + ['confirm_implausible' => '1'], $this->userId);
        self::assertNull($first->id);
        self::assertSame([], $this->rows('measurements'));
        self::assertSame([], $this->rows('audit'));
        self::assertFalse($this->pdo->inTransaction());
        $saved = $service->record((string) $this->childId, $input + ['confirm_implausible' => '1', 'confirmation_token' => $first->confirmationToken], $this->userId);
        self::assertNotNull($saved->id);
        self::assertSame(1, (int) $this->rows('measurements')[0]['implausible_confirmed']);
        self::assertCount(1, $this->rows('audit'));
    }

    public function test_AC11_audit_failure_rolls_back_the_real_measurement_insert(): void
    {
        $audit = new class ($this->pdo) extends AuditRepository {
            public function insert(int $userId, string $action, string $entity, int $entityId, string $changes, ?string $ipAddress): void
            {
                parent::insert($userId, $action, $entity, $entityId, $changes, $ipAddress);
                throw new RuntimeException('FAKE audit failure after insert');
            }
        };
        try {
            $this->service($audit)->record((string) $this->childId, $this->input(), $this->userId);
            self::fail('Expected injected audit failure.');
        } catch (RuntimeException $error) {
            self::assertSame('FAKE audit failure after insert', $error->getMessage());
        }
        self::assertSame([], $this->rows('measurements'));
        self::assertSame([], $this->rows('audit'));
        self::assertFalse($this->pdo->inTransaction());
    }

    public function test_AC06_database_requires_valid_positive_values_and_existing_child_and_actor(): void
    {
        $result = $this->service()->record((string) $this->childId, $this->input(), $this->userId);
        foreach (['weight_kg' => 0, 'height_cm' => 0, 'muac_cm' => 0, 'bmi' => 0, 'implausible_confirmed' => 2, 'child_id' => 0, 'measured_by' => 0] as $field => $value) {
            $this->pdo->beginTransaction();
            try {
                (new MeasurementRepository($this->pdo))->insert(array_replace($result->data, [$field => $value]));
                self::fail('Expected database constraint failure: ' . $field);
            } catch (PDOException $error) {
                self::assertNotEmpty($error->getCode());
            } finally {
                $this->pdo->rollBack();
            }
        }
        self::assertCount(1, $this->rows('measurements'));
    }
}
