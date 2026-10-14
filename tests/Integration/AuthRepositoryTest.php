<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Core\Session;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Middleware\AuthMiddleware;
use Pediacare\Middleware\RoleMiddleware;
use Pediacare\Support\Permissions;
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\AuthService;
use Pediacare\Services\MigrationService;
use Pediacare\Services\UserService;
use Pediacare\Validation\UserValidator;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthRepositoryTest extends TestCase
{
    private PDO $pdo;
    private array $config;

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
        $this->pdo->beginTransaction();
        (new MigrationRepository($this->pdo))->execute('DELETE FROM login_attempts');
        (new MigrationRepository($this->pdo))->execute('DELETE FROM audit_logs');
        (new MigrationRepository($this->pdo))->execute('DELETE FROM children');
        (new MigrationRepository($this->pdo))->execute('DELETE FROM users');
        session_save_path(sys_get_temp_dir());
        (new Session())->start(false);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function test_T09_fifth_failure_blocks_sixth_login_and_window_expiry_allows_login(): void
    {
        $users = new UserRepository($this->pdo);
        $users->insertAdmin('FAKE Auth Admin', 'fake-auth@example.invalid',
            password_hash('FAKE_password', PASSWORD_BCRYPT, ['cost' => 4]));
        $attempts = new LoginAttemptRepository($this->pdo);
        $session = new Session();
        $auth = new AuthService($users, $attempts, $session);
        $now = new DateTimeImmutable('2026-01-01 12:00:00');

        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($auth->login('fake-auth@example.invalid', 'FAKE_wrong', '192.0.2.1', $now));
        }

        self::assertFalse($auth->login('fake-auth@example.invalid', 'FAKE_password', '192.0.2.1', $now));
        self::assertNull($session->userId());
        self::assertSame(['email' => 5, 'ip' => 5], $attempts->failures(
            'fake-auth@example.invalid', '192.0.2.1', '2026-01-01 11:45:00'
        ));
        self::assertTrue($auth->login('fake-auth@example.invalid', 'FAKE_password', '192.0.2.1', $now->modify('+15 minutes')));
        $user = $users->findByEmail('fake-auth@example.invalid');
        self::assertSame('2026-01-01 12:15:00', $user['last_login_at']);
        self::assertSame((int) $user['id'], $session->userId());
        self::assertSame(defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt', password_get_info($user['password_hash'])['algoName']);
        self::assertSame(['email' => 0, 'ip' => 0], $attempts->failures(
            'fake-auth@example.invalid', '192.0.2.1', '2026-01-01 12:00:00'
        ));
    }

    public function test_T09_failure_counts_are_separate_for_email_and_ip_and_exclude_successes(): void
    {
        $attempts = new LoginAttemptRepository($this->pdo);
        $attempts->record('fake-a@example.invalid', '192.0.2.1', '2026-01-01 12:00:00', false);
        $attempts->record('fake-b@example.invalid', '192.0.2.1', '2026-01-01 12:00:00', false);
        $attempts->record('fake-a@example.invalid', '192.0.2.2', '2026-01-01 12:00:00', true);

        self::assertSame(['email' => 1, 'ip' => 2], $attempts->failures(
            'fake-a@example.invalid', '192.0.2.1', '2026-01-01 11:45:00'
        ));
        self::assertSame(['email' => 0, 'ip' => 0], $attempts->failures(
            "fake-'quoted@example.invalid", '192.0.2.99', '2026-01-01 11:45:00'
        ));
    }

    public function test_T09_login_lock_excludes_other_connections_and_is_released(): void
    {
        $attempts = new LoginAttemptRepository($this->pdo);
        $other = Database::connect($this->config);
        $name = 'FAKE_auth_lock_' . bin2hex(random_bytes(8));
        $attempts->lock($name);

        try {
            $statement = $other->prepare('SELECT GET_LOCK(:name, 0)');
            $statement->execute(['name' => $name]);
            self::assertSame(0, (int) $statement->fetchColumn());
        } finally {
            $attempts->unlock($name);
        }

        $statement->execute(['name' => $name]);
        self::assertSame(1, (int) $statement->fetchColumn());
        (new LoginAttemptRepository($other))->unlock($name);
    }

    public function test_AC01_AC03_database_role_change_and_deactivation_revoke_existing_session_access(): void
    {
        $users = new UserRepository($this->pdo);
        $users->insertAdmin('FAKE Authorization Admin', 'fake-authorization@example.invalid', 'FAKE_HASH');
        $user = $users->findByEmail('fake-authorization@example.invalid');
        $session = new Session();
        $session->renew((int) $user['id']);
        $service = new AuthService($users, new LoginAttemptRepository($this->pdo), $session);
        $auth = new AuthMiddleware($service);
        $roles = new RoleMiddleware($service);
        $request = new Request('GET', '/FAKE-child');
        $permitted = new Response('FAKE permitted');
        $next = static fn (): Response => $permitted;
        self::assertSame($permitted, $auth->handle($request, $next));
        self::assertSame($permitted, $roles->handle($request, $next, Permissions::roles('children.read')));
        $statement = $this->pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
        $statement->execute(['role' => 'VIEWER', 'id' => $user['id']]);
        self::assertSame(403, $roles->handle($request, $next, Permissions::roles('children.read'))->status);
        self::assertSame($permitted, $roles->handle($request, $next, Permissions::roles('reports.aggregate')));
        $statement = $this->pdo->prepare('UPDATE users SET active = 0 WHERE id = :id');
        $statement->execute(['id' => $user['id']]);
        self::assertSame('/login', $auth->handle($request, $next)->headers['Location']);
        self::assertSame(403, $roles->handle($request, $next, Permissions::roles('reports.aggregate'))->status);
    }

    public function test_AC02_managed_account_can_login_change_role_and_be_deactivated_without_deletion(): void
    {
        $repository = new UserRepository($this->pdo);
        $service = new UserService($repository, new UserValidator());
        $service->create([
            'name' => "FAKE 'quoted Staff", 'email' => 'FAKE-managed@example.invalid',
            'password' => 'FAKE_MANAGED_password', 'role' => 'STAFF',
        ]);
        $user = $repository->findByEmail('fake-managed@example.invalid');
        self::assertSame("FAKE 'quoted Staff", $user['name']);
        self::assertSame('STAFF', $user['role']);
        self::assertTrue(password_verify('FAKE_MANAGED_password', $user['password_hash']));
        self::assertArrayNotHasKey('password_hash', $service->all()[0]);
        $auth = new AuthService($repository, new LoginAttemptRepository($this->pdo), new Session());
        self::assertTrue($auth->login('fake-managed@example.invalid', 'FAKE_MANAGED_password', '192.0.2.1'));
        $service->changeRole((string) $user['id'], ['role' => 'BHW']);
        self::assertSame('BHW', $auth->currentUser()['role']);
        $service->deactivate((string) $user['id']);
        self::assertNull($auth->currentUser());
        self::assertSame(0, (int) $repository->findById((int) $user['id'])['active']);
        self::assertSame($user['id'], $repository->findByEmail('fake-managed@example.invalid')['id']);
        self::assertFalse($auth->login('fake-managed@example.invalid', 'FAKE_MANAGED_password', '192.0.2.1'));

        try {
            $service->create([
                'name' => 'FAKE Replacement', 'email' => 'fake-managed@example.invalid', 'password' => 'FAKE_password', 'role' => 'ADMIN',
            ]);
            self::fail('Duplicate email was accepted.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('An account already uses this email address.', $error->getMessage());
        }

        self::assertCount(1, $service->all());
        self::assertSame('BHW', $service->all()[0]['role']);
    }
}
