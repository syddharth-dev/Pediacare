<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Repositories\{AuditRepository, ChildRepository, MigrationRepository,
    PurokRepository, RecordCounterRepository, UserRepository};
use Pediacare\Services\{ChildService, MigrationService};
use Pediacare\Support\{AuditLogger, RecordNumberGenerator};
use Pediacare\Validation\ChildValidator;
use PHPUnit\Framework\TestCase;

final class BackupRestoreTest extends TestCase
{
    private PDO $pdo;
    private array $config;
    private string $directory;
    private string $privateDirectory;
    private int $actor;
    private int $purok;
    private bool $createdRecovery = false;
    private array $countersBefore = [];
    private const TARGET = 'pediacare_restore_test';
    private const TABLES = ['migrations', 'users', 'login_attempts', 'puroks', 'children', 'record_counters',
        'measurements', 'vaccines', 'schedule_doses', 'immunizations', 'visits', 'supplements', 'who_lms', 'audit_logs'];

    protected function setUp(): void
    {
        $path = getenv('PEDIACARE_TEST_CONFIG') ?: dirname(__DIR__, 2) . '/config/config.local.php';
        if (!is_file($path)) {
            self::markTestSkipped('Configure pediacare_test to run MySQL integration tests.');
        }
        $this->config = require $path;
        $database = $this->config['database'];
        self::assertSame('pediacare_test', $database['test_name']);
        self::assertNotSame($database['name'], $database['test_name']);
        self::assertNotSame(self::TARGET, $database['name']);
        $database['name'] = $database['test_name'];
        $this->pdo = Database::connect($database);
        (new MigrationService(new MigrationRepository($this->pdo)))->run(dirname(__DIR__, 2) . '/database/migrations');
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = :name');
        $statement->execute(['name' => self::TARGET]);
        self::assertSame(0, (int) $statement->fetchColumn(), 'Recovery test database must not already exist.');
        $this->pdo->prepare('CREATE DATABASE pediacare_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')->execute();
        $this->createdRecovery = true;
        $this->directory = sys_get_temp_dir() . '/pediacare-backup-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        foreach (['bin', 'src', 'vendor', 'config', 'storage/logs'] as $folder) {
            mkdir($this->directory . '/' . $folder, 0700, true);
        }
        $example = require dirname(__DIR__, 2) . '/config/config.example.php';
        $this->config['backup'] = $example['backup'];
        $this->privateDirectory = $this->directory . '-private space & test';
        mkdir($this->privateDirectory, 0700);
        $this->config['backup']['directory'] = $this->privateDirectory;
        $this->config['backup']['key'] = base64_encode(random_bytes(32));
        foreach (['backup.php', 'restore.php'] as $file) {
            copy(dirname(__DIR__, 2) . '/bin/' . $file, $this->directory . '/bin/' . $file);
        }
        copy(dirname(__DIR__, 2) . '/src/bootstrap.php', $this->directory . '/src/bootstrap.php');
        file_put_contents($this->directory . '/vendor/autoload.php', "<?php\ndeclare(strict_types=1);\nrequire "
            . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n");
        $this->writeConfig();
        $this->countersBefore = $this->rows($this->pdo, 'SELECT * FROM record_counters');
        $email = 'fake-backup-' . bin2hex(random_bytes(6)) . '@example.invalid';
        $users = new UserRepository($this->pdo);
        $users->insertAdmin('FAKE Backup Admin', $email, password_hash('FAKE_TEST_ONLY_backup', PASSWORD_DEFAULT));
        $this->actor = (int) $users->findByEmail($email)['id'];
        $puroks = new PurokRepository($this->pdo);
        $puroks->insert('FAKE Backup Purok ' . $this->actor);
        $this->purok = (int) $this->pdo->lastInsertId();
        $input = require dirname(__DIR__) . '/Fixtures/child.php';
        $input['purok_id'] = (string) $this->purok;
        $input['address'] = "FAKE Ñ Address 'quoted' \\ slash\nsecond line";
        $input['notes'] = "FAKE PRIVATE HEALTH DATA\nline two";
        $service = new ChildService($this->pdo, new ChildRepository($this->pdo), $puroks, new ChildValidator(),
            new RecordNumberGenerator(new RecordCounterRepository($this->pdo)), new AuditLogger(new AuditRepository($this->pdo)));
        $result = $service->register($input, $this->actor);
        $service->archive((string) $result->childId, ['reason' => 'FAKE moved away', 'confirm' => '1'], $this->actor);
    }

    protected function tearDown(): void
    {
        if (isset($this->actor)) {
            foreach (['DELETE FROM audit_logs WHERE user_id = :id', 'DELETE FROM children WHERE created_by = :id',
                'DELETE FROM users WHERE id = :id'] as $sql) {
                $this->pdo->prepare($sql)->execute(['id' => $this->actor]);
            }
            $this->pdo->prepare('DELETE FROM puroks WHERE id = :id')->execute(['id' => $this->purok]);
            $this->pdo->prepare('DELETE FROM record_counters')->execute();
            foreach ($this->countersBefore as $row) {
                $this->pdo->prepare('INSERT INTO record_counters (year, last_number) VALUES (:year, :last_number)')->execute($row);
            }
        }
        if ($this->createdRecovery) {
            $this->pdo->prepare('DROP DATABASE pediacare_restore_test')->execute();
        }
        if (isset($this->directory)) {
            foreach (['bin', 'src', 'vendor', 'config', 'storage/logs'] as $folder) {
                foreach (glob($this->directory . '/' . $folder . '/*') as $file) {
                    unlink($file);
                }
                foreach (glob($this->directory . '/' . $folder . '/.*') as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($this->directory . '/' . $folder);
            }
            rmdir($this->directory . '/storage');
            rmdir($this->directory);
        }
        if (isset($this->privateDirectory)) {
            foreach (glob($this->privateDirectory . '/*') as $file) {
                unlink($file);
            }
            foreach (glob($this->privateDirectory . '/.*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->privateDirectory);
        }
    }

    public function test_AC05_AC11_AC12_T40_cli_backup_restores_schema_fake_records_and_audits_exactly(): void
    {
        $before = $this->snapshot($this->pdo);
        [$status, $stdout, $stderr] = $this->command('backup.php', ['--test']);
        self::assertSame(0, $status, $stderr . ($status === 0 ? '' : file_get_contents($this->directory . '/storage/logs/app.log')));
        self::assertSame('', $stderr);
        self::assertStringContainsString('Encrypted backup created:', $stdout);
        $files = glob($this->privateDirectory . '/*.pcbackup');
        self::assertCount(1, $files);
        $file = $files[0];
        $bytes = file_get_contents($file);
        self::assertStringNotContainsString('FAKE PRIVATE HEALTH DATA', $bytes);
        self::assertStringNotContainsString('CREATE TABLE', $bytes);
        self::assertSame([], glob($this->privateDirectory . '/.*.cnf'));
        $recovery = $this->config['database'];
        $recovery['name'] = self::TARGET;
        $target = Database::connect($recovery);
        $bad = $this->privateDirectory . '/damaged.pcbackup';
        file_put_contents($bad, substr($bytes, 0, -1));
        [$status, $stdout, $stderr] = $this->command('restore.php', [$bad, self::TARGET]);
        self::assertSame(1, $status);
        self::assertSame([], $this->rows($target, 'SHOW TABLES'));
        self::assertStringNotContainsString('FAKE PRIVATE', $stderr);
        [$status, $stdout, $stderr] = $this->command('restore.php', [$file, self::TARGET]);
        self::assertSame(0, $status, $stderr);
        self::assertSame('', $stderr);
        self::assertSame($before, $this->snapshot($target));
        foreach ([self::TARGET, 'pediacare_test', strtoupper($this->config['database']['name'])] as $name) {
            [$status, $stdout, $stderr] = $this->command('restore.php', [$file, $name]);
            self::assertSame(1, $status);
            self::assertSame($before, $this->snapshot($target));
            self::assertSame($before, $this->snapshot($this->pdo));
        }
        // A failed dump must publish neither a completed backup nor plaintext/error output.
        $this->config['database']['password'] = 'FAKE_WRONG_PASSWORD';
        $this->writeConfig();
        [$status, $stdout, $stderr] = $this->command('backup.php', ['--test']);
        self::assertSame(1, $status);
        self::assertSame('', $stdout);
        self::assertCount(2, glob($this->privateDirectory . '/*.pcbackup'));
        self::assertSame([], glob($this->privateDirectory . '/*.part'));
        self::assertSame([], glob($this->privateDirectory . '/.*.cnf'));
        self::assertStringNotContainsString('FAKE PRIVATE', file_get_contents($this->directory . '/storage/logs/app.log'));
    }

    private function writeConfig(): void
    {
        file_put_contents($this->directory . '/config/config.local.php', "<?php\ndeclare(strict_types=1);\nreturn "
            . var_export($this->config, true) . ";\n");
    }

    private function command(string $script, array $arguments): array
    {
        $process = proc_open([PHP_BINARY, $this->directory . '/bin/' . $script, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function snapshot(PDO $pdo): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $snapshot[$table] = $this->rows($pdo, 'SELECT * FROM ' . $table . ' ORDER BY ' . ($table === 'record_counters' ? 'year' : 'id'));
        }

        return $snapshot;
    }

    private function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute();

        return $statement->fetchAll();
    }
}
