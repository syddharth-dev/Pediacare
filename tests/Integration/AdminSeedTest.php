<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\AdminSeedService;
use Pediacare\Services\MigrationService;
use PHPUnit\Framework\TestCase;

final class AdminSeedTest extends TestCase
{
    private PDO $pdo;
    private array $databaseConfig;

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
        $this->databaseConfig = $config;
        $this->pdo = Database::connect($config);
        (new MigrationService(new MigrationRepository($this->pdo)))->run(dirname(__DIR__, 2) . '/database/migrations');
        $this->pdo->beginTransaction();
        // Only the designated test database is touched; these deletions are rolled back.
        (new MigrationRepository($this->pdo))->execute('DELETE FROM audit_logs');
        (new MigrationRepository($this->pdo))->execute('DELETE FROM children');
        (new MigrationRepository($this->pdo))->execute('DELETE FROM users');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function test_T08_seed_persists_active_admin_and_rerun_preserves_inactive_account(): void
    {
        $service = new AdminSeedService(new UserRepository($this->pdo));
        self::assertTrue($service->run([
            'name' => "FAKE Admin ' quoted",
            'email' => 'fake-admin@example.invalid',
            'password' => 'FAKE_TEST_ONLY_password_123',
        ]));
        $statement = $this->pdo->prepare('SELECT * FROM users');
        $statement->execute();
        $user = $statement->fetch();
        self::assertSame("FAKE Admin ' quoted", $user['name']);
        self::assertSame('ADMIN', $user['role']);
        self::assertSame(1, (int) $user['active']);
        self::assertNull($user['last_login_at']);
        self::assertNotEmpty($user['created_at']);
        self::assertTrue(password_verify('FAKE_TEST_ONLY_password_123', $user['password_hash']));
        (new MigrationRepository($this->pdo))->execute('UPDATE users SET active = 0');
        self::assertFalse($service->run([]));
        $statement->execute();
        $rows = $statement->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame(0, (int) $rows[0]['active']);
        self::assertSame($user['password_hash'], $rows[0]['password_hash']);
    }

    public function test_T08_duplicate_email_fails_without_promoting_or_replacing_account(): void
    {
        $repository = new UserRepository($this->pdo);
        $repository->insertAdmin('FAKE Staff', 'fake-staff@example.invalid', 'FAKE_HASH');
        (new MigrationRepository($this->pdo))->execute("UPDATE users SET role = 'STAFF'");

        try {
            (new AdminSeedService($repository))->run([
                'name' => 'FAKE Admin',
                'email' => 'fake-staff@example.invalid',
                'password' => 'FAKE_TEST_ONLY_password_123',
            ]);
            self::fail('Duplicate email was accepted.');
        } catch (PDOException $error) {
            self::assertSame('23000', $error->getCode());
        }

        $statement = $this->pdo->prepare('SELECT name, role, password_hash FROM users');
        $statement->execute();
        self::assertSame([
            ['name' => 'FAKE Staff', 'role' => 'STAFF', 'password_hash' => 'FAKE_HASH'],
        ], $statement->fetchAll());
    }

    public function test_T08_login_attempt_schema_stores_ipv6_and_default_timestamp(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (email, ip_address, success) VALUES (:email, :ip, :success)'
        );
        $statement->execute(['email' => 'fake@example.invalid', 'ip' => '2001:db8::1', 'success' => 0]);
        $id = $this->pdo->lastInsertId();
        $statement = $this->pdo->prepare('SELECT * FROM login_attempts WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        self::assertSame('2001:db8::1', $row['ip_address']);
        self::assertSame(0, (int) $row['success']);
        self::assertNotEmpty($row['attempted_at']);
    }

    public function test_T08_cli_seed_creates_once_without_printing_credentials(): void
    {
        // The subprocess needs committed rows; restore this test's changes explicitly below.
        $this->pdo->rollBack();
        $email = 'fake-cli-' . bin2hex(random_bytes(8)) . '@example.invalid';
        $directory = sys_get_temp_dir() . '/pediacare-seed-' . bin2hex(random_bytes(8));
        $files = [];
        mkdir($directory);

        foreach (['bin', 'src', 'config', 'vendor', 'storage', 'storage/logs'] as $subdirectory) {
            mkdir($directory . '/' . $subdirectory);
        }

        try {
            $files = ['/bin/seed.php', '/src/bootstrap.php', '/vendor/autoload.php', '/config/config.local.php'];
            copy(dirname(__DIR__, 2) . '/bin/seed.php', $directory . $files[0]);
            copy(dirname(__DIR__, 2) . '/src/bootstrap.php', $directory . $files[1]);
            file_put_contents($directory . $files[2], '<?php declare(strict_types=1); require '
                . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';');
            $config = ['database' => $this->databaseConfig, 'first_admin' => [
                'name' => 'FAKE CLI Admin', 'email' => $email, 'password' => 'FAKE_CLI_TEST_ONLY_password',
            ]];
            file_put_contents($directory . $files[3], '<?php declare(strict_types=1); return '
                . var_export($config, true) . ';');

            foreach (["First Admin created.\n", "Admin already exists; no accounts changed.\n"] as $expected) {
                $process = proc_open([PHP_BINARY, $directory . '/bin/seed.php'], [
                    1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes);
                self::assertIsResource($process);
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
                self::assertSame($expected, $output);
                self::assertSame('', $errors);
            }
        } finally {
            $statement = $this->pdo->prepare('DELETE FROM users WHERE email = :email');
            $statement->execute(['email' => $email]);

            foreach ($files as $file) {
                if (is_file($directory . $file)) {
                    unlink($directory . $file);
                }
            }

            if (is_file($directory . '/storage/logs/app.log')) {
                unlink($directory . '/storage/logs/app.log');
            }

            foreach (['storage/logs', 'storage', 'vendor', 'config', 'src', 'bin', ''] as $subdirectory) {
                rmdir($directory . '/' . $subdirectory);
            }
        }
    }
}
