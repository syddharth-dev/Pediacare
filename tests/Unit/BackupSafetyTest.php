<?php

declare(strict_types=1);

use Pediacare\Repositories\BackupRepository;
use Pediacare\Services\BackupService;
use Pediacare\Support\BackupCipher;
use PHPUnit\Framework\TestCase;

final class BackupSafetyTest extends TestCase
{
    public function test_T40_backup_locations_inside_the_project_are_rejected(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([$root, $root . '/public', $root . '/storage/logs'] as $directory) {
            try {
                $this->service($directory, $root);
                self::fail('A project directory was accepted for private backups.');
            } catch (RuntimeException $error) {
                self::assertSame('Backups must be outside the project directory.', $error->getMessage());
            }
        }
    }

    public function test_T40_protected_database_names_are_rejected_before_opening_a_backup(): void
    {
        $service = $this->service(sys_get_temp_dir(), dirname(__DIR__, 2));
        foreach (['pediacare', 'PEDIACARE', 'pediacare_test'] as $database) {
            try {
                $service->restore('/FAKE_NONEXISTENT_FILE', $database);
                self::fail('A protected restore target was accepted.');
            } catch (RuntimeException $error) {
                self::assertSame('Restore cannot target the application or test database.', $error->getMessage());
            }
        }
    }

    public function test_T40_authentication_failure_precedes_any_database_connection(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pediacare-cipher-');
        $key = base64_encode(random_bytes(32));
        file_put_contents($file, (new BackupCipher($key))->encrypt('FAKE SQL'));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Backup authentication failed.');
            $this->service(sys_get_temp_dir(), dirname(__DIR__, 2))->restore($file, 'pediacare_restore_test');
        } finally {
            unlink($file);
        }
    }

    private function service(string $directory, string $root): BackupService
    {
        $database = (require dirname(__DIR__, 2) . '/config/config.example.php')['database'];
        $database['port'] = 1;

        return new BackupService(new BackupRepository($database, '/FAKE_MISSING_CLIENT', '/FAKE_MISSING_CLIENT', $directory),
            new BackupCipher(base64_encode(random_bytes(32))), $directory, $root, ['pediacare', 'pediacare_test']);
    }
}
