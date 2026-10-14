<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Repositories\{AuditRepository, ChildRepository, DemoSeedRepository, ImmunizationRepository,
    MeasurementRepository, MigrationRepository, PurokRepository, RecordCounterRepository, SupplementRepository,
    UserRepository, VaccineScheduleRepository, VisitRepository, WhoLmsRepository};
use Pediacare\Services\{ChildService, DemoSeedService, ImmunizationService, MeasurementService, MigrationService,
    VaccineScheduleSeedService, VisitService, WhoLmsSeedService};
use Pediacare\Support\{AuditLogger, MeasurementConfirmation, RecordNumberGenerator};
use Pediacare\Validation\{ChildValidator, ImmunizationValidator, MeasurementValidator, VisitValidator};
use PHPUnit\Framework\TestCase;

final class DemoSeedTest extends TestCase
{
    private PDO $pdo;
    private int $actor;
    private array $whoBefore = [];
    private array $puroksBefore = [];
    private array $vaccinesBefore = [];
    private array $dosesBefore = [];
    private array $countersBefore = [];
    private array $confirmation = [];
    private array $config;

    protected function setUp(): void
    {
        $path = getenv('PEDIACARE_TEST_CONFIG') ?: dirname(__DIR__, 2) . '/config/config.local.php';
        if (!is_file($path)) {
            self::markTestSkipped('Configure pediacare_test to run MySQL integration tests.');
        }
        $this->config = require $path;
        $config = $this->config['database'];
        self::assertSame('pediacare_test', $config['test_name']);
        self::assertNotSame($config['name'], $config['test_name']);
        $config['name'] = $config['test_name'];
        $this->pdo = Database::connect($config);
        (new MigrationService(new MigrationRepository($this->pdo)))->run(dirname(__DIR__, 2) . '/database/migrations');
        self::assertSame([], $this->rows('SELECT id FROM children'), 'Demo integration test requires an empty test registry.');
        $this->whoBefore = $this->rows('SELECT * FROM who_lms');
        $this->puroksBefore = array_column($this->rows('SELECT id FROM puroks'), 'id');
        $this->vaccinesBefore = array_column($this->rows('SELECT id FROM vaccines'), 'id');
        $this->dosesBefore = array_column($this->rows('SELECT id FROM schedule_doses'), 'id');
        $this->countersBefore = $this->rows('SELECT * FROM record_counters');
        $email = 'fake-demo-' . bin2hex(random_bytes(6)) . '@example.invalid';
        $users = new UserRepository($this->pdo);
        $users->insertAdmin('FAKE Demo Test Admin', $email, password_hash('FAKE_TEST_ONLY_demo', PASSWORD_DEFAULT));
        $this->actor = (int) $users->findByEmail($email)['id'];
    }

    protected function tearDown(): void
    {
        if (!isset($this->actor)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        foreach (['DELETE s FROM supplements s JOIN visits v ON v.id = s.visit_id WHERE v.attended_by = :id',
            'DELETE FROM visits WHERE attended_by = :id', 'DELETE FROM immunizations WHERE given_by = :id',
            'DELETE FROM measurements WHERE measured_by = :id', 'DELETE FROM audit_logs WHERE user_id = :id',
            'DELETE FROM children WHERE created_by = :id', 'DELETE FROM users WHERE id = :id'] as $sql) {
            $this->pdo->prepare($sql)->execute(['id' => $this->actor]);
        }
        foreach (['schedule_doses' => $this->dosesBefore, 'vaccines' => $this->vaccinesBefore,
            'puroks' => $this->puroksBefore] as $table => $before) {
            foreach ($this->rows('SELECT id FROM ' . $table) as $row) {
                if (!in_array($row['id'], $before, true)) {
                    $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE id = :id')->execute(['id' => $row['id']]);
                }
            }
        }
        $this->pdo->beginTransaction();
        $this->pdo->prepare('DELETE FROM who_lms')->execute();
        $restore = $this->pdo->prepare('INSERT INTO who_lms (id, reference, indicator, sex, age_or_size, l, m, s)
            VALUES (:id, :reference, :indicator, :sex, :age_or_size, :l, :m, :s)');
        foreach ($this->whoBefore as $row) {
            $restore->execute($row);
        }
        $this->pdo->prepare('DELETE FROM record_counters')->execute();
        $restore = $this->pdo->prepare('INSERT INTO record_counters (year, last_number) VALUES (:year, :last_number)');
        foreach ($this->countersBefore as $row) {
            $restore->execute($row);
        }
        $this->pdo->commit();
    }

    public function test_AC05_AC06_AC07_AC08_AC11_AC12_demo_seed_creates_fake_histories_and_preserves_them_on_rerun(): void
    {
        $service = $this->service();
        try {
            $service->run(0);
            self::fail('An invalid actor was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('active Admin', $error->getMessage());
            self::assertSame([], $this->rows('SELECT id FROM children'));
        }
        (new WhoLmsSeedService($this->pdo, new WhoLmsRepository($this->pdo)))->run(dirname(__DIR__, 2) . '/database/who');
        $schedule = new VaccineScheduleRepository($this->pdo);
        (new VaccineScheduleSeedService($this->pdo, $schedule))->run(require dirname(__DIR__, 2) . '/database/seeds/vaccine-schedule.php');
        $scheduleBefore = $schedule->all();
        $userBefore = (new UserRepository($this->pdo))->findById($this->actor);
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
        $counts = $this->command();
        self::assertSame(200, $counts['children']);
        self::assertSame(100, $counts['visits']);
        self::assertSame(10, $counts['archived']);
        self::assertGreaterThan(180, $counts['measurements']);
        self::assertGreaterThan(500, $counts['immunizations']);
        self::assertSame($scheduleBefore, $schedule->all());
        self::assertSame($userBefore, (new UserRepository($this->pdo))->findById($this->actor));
        $children = $this->rows('SELECT * FROM children ORDER BY id');
        self::assertCount(200, $children);
        self::assertCount(200, array_unique(array_column($children, 'record_no')));
        $ages = [];
        foreach ($children as $child) {
            self::assertMatchesRegularExpression('/\APC-' . $today->format('Y') . '-[0-9]{5}\z/', $child['record_no']);
            foreach (['first_name', 'last_name', 'address', 'mother_name', 'guardian_name', 'contact_no', 'notes'] as $field) {
                self::assertStringContainsString('FAKE', $child[$field]);
            }
            self::assertNull($child['philhealth_no']);
            $age = (new DateTimeImmutable($child['date_of_birth'], $today->getTimezone()))->diff($today);
            $ages[] = $age->y * 12 + $age->m;
        }
        self::assertSame(0, min($ages));
        self::assertSame(59, max($ages));
        self::assertCount(2, array_unique(array_column($children, 'sex')));
        self::assertCount(5, array_unique(array_column($children, 'purok_id')));
        foreach (['measurements', 'immunizations', 'visits'] as $entity) {
            self::assertCount($counts[$entity], $this->rows('SELECT id FROM ' . $entity));
        }
        self::assertCount(100, $this->rows('SELECT id FROM supplements'));
        self::assertSame($counts['children'] + $counts['measurements'] + $counts['immunizations'] + 200,
            count($this->rows("SELECT id FROM audit_logs WHERE action = 'CREATE' AND user_id = :actor", ['actor' => $this->actor])));
        self::assertCount(10, $this->rows("SELECT id FROM audit_logs WHERE action = 'ARCHIVE' AND user_id = :actor", ['actor' => $this->actor]));
        self::assertCount(190, $this->rows('SELECT id FROM children WHERE archived_at IS NULL'));
        self::assertNotEmpty($this->rows("SELECT id FROM measurements WHERE wfa_status <> 'Normal' OR bmi_status <> 'Normal'"));
        self::assertNotEmpty($this->rows('SELECT id FROM immunizations WHERE given_elsewhere = 1'));
        self::assertSame([], $this->rows('SELECT id FROM measurements WHERE implausible_confirmed = 1'));
        $complete = $overdue = $upcoming = false;
        $immunizations = new ImmunizationService($this->pdo, new ChildRepository($this->pdo), $schedule,
            new ImmunizationRepository($this->pdo), new ImmunizationValidator(), new AuditLogger(new AuditRepository($this->pdo)));
        foreach ($children as $child) {
            $grid = $immunizations->forChild($child, $today);
            $complete = $complete || $grid['fic'];
            foreach ($grid['vaccines'] as $vaccine) {
                foreach ($vaccine['doses'] as $dose) {
                    $overdue = $overdue || $dose['status'] === 'Overdue';
                    $upcoming = $upcoming || $dose['status'] === 'Upcoming';
                }
            }
        }
        self::assertTrue($complete);
        self::assertTrue($overdue);
        self::assertTrue($upcoming);
        $auditBefore = $this->rows('SELECT * FROM audit_logs');
        try {
            $service->run($this->actor, $today->modify('+1 day'));
            self::fail('A nonempty registry was seeded twice.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('empty child registry', $error->getMessage());
        }
        self::assertSame($children, $this->rows('SELECT * FROM children ORDER BY id'));
        self::assertSame($auditBefore, $this->rows('SELECT * FROM audit_logs'));
    }

    private function service(): DemoSeedService
    {
        $children = new ChildRepository($this->pdo);
        $puroks = new PurokRepository($this->pdo);
        $references = new WhoLmsRepository($this->pdo);
        $schedule = new VaccineScheduleRepository($this->pdo);
        $audit = new AuditLogger(new AuditRepository($this->pdo));

        return new DemoSeedService(new DemoSeedRepository($this->pdo), new UserRepository($this->pdo), $puroks,
            $schedule, $references,
            new ChildService($this->pdo, $children, $puroks, new ChildValidator(),
                new RecordNumberGenerator(new RecordCounterRepository($this->pdo)), $audit),
            new MeasurementService($this->pdo, $children, new MeasurementRepository($this->pdo), $references,
                new MeasurementValidator(), $audit, new MeasurementConfirmation($this->confirmation)),
            new ImmunizationService($this->pdo, $children, $schedule, new ImmunizationRepository($this->pdo),
                new ImmunizationValidator(), $audit),
            new VisitService($this->pdo, $children, new VisitRepository($this->pdo), new SupplementRepository($this->pdo),
                new VisitValidator(), $audit));
    }

    private function command(): array
    {
        $directory = sys_get_temp_dir() . '/pediacare-demo-command-' . bin2hex(random_bytes(8));
        foreach (['bin', 'src', 'vendor', 'config', 'storage/logs'] as $folder) {
            mkdir($directory . '/' . $folder, 0700, true);
        }
        copy(dirname(__DIR__, 2) . '/bin/seed-demo.php', $directory . '/bin/seed-demo.php');
        copy(dirname(__DIR__, 2) . '/src/bootstrap.php', $directory . '/src/bootstrap.php');
        file_put_contents($directory . '/config/config.local.php', "<?php\ndeclare(strict_types=1);\nreturn "
            . var_export($this->config, true) . ";\n");
        file_put_contents($directory . '/vendor/autoload.php', "<?php\ndeclare(strict_types=1);\nrequire "
            . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n");
        try {
            $process = proc_open([PHP_BINARY, $directory . '/bin/seed-demo.php', '--demo', '--test'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr);
            self::assertSame('', $stderr);
            self::assertSame(1, preg_match('/FAKE demo data created: (\d+) children, (\d+) measurements, (\d+) vaccine records, (\d+) visits; (\d+) children archived/', $stdout, $matches));

            return array_combine(['children', 'measurements', 'immunizations', 'visits', 'archived'],
                array_map('intval', array_slice($matches, 1)));
        } finally {
            foreach (['bin/seed-demo.php', 'src/bootstrap.php', 'vendor/autoload.php', 'config/config.local.php', 'storage/logs/app.log'] as $file) {
                if (is_file($directory . '/' . $file)) {
                    unlink($directory . '/' . $file);
                }
            }
            foreach (['bin', 'src', 'vendor', 'config', 'storage/logs', 'storage'] as $folder) {
                rmdir($directory . '/' . $folder);
            }
            rmdir($directory);
        }
    }

    private function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }
}
