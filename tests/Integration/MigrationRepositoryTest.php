<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Repositories\MigrationRepository;
use PHPUnit\Framework\TestCase;

final class MigrationRepositoryTest extends TestCase
{
    public function test_T06_applied_filenames_are_bound_and_read_back_with_mysql(): void
    {
        $configPath = getenv('PEDIACARE_TEST_CONFIG') ?: dirname(__DIR__, 2) . '/config/config.local.php';

        if (!is_file($configPath)) {
            self::markTestSkipped('Configure the separate pediacare_test database to run MySQL integration tests.');
        }

        $config = (require $configPath)['database'];
        self::assertSame('pediacare_test', $config['test_name']);
        self::assertNotSame($config['name'], $config['test_name']);
        $config['name'] = $config['test_name'];
        $pdo = Database::connect($config);
        $repository = new MigrationRepository($pdo);
        $repository->execute(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/000_create_migrations.sql'));
        $before = $repository->applied();
        $filename = "999_FAKE_'quoted_" . bin2hex(random_bytes(8)) . '.sql';
        $pdo->beginTransaction();

        try {
            $repository->record($filename);
            self::assertSame([...$before, $filename], $repository->applied());
            self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
            self::assertFalse((bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
            $this->expectException(PDOException::class);
            $repository->record($filename);
        } finally {
            $pdo->rollBack();
        }
    }
}
