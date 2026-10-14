<?php

declare(strict_types=1);

use Pediacare\Repositories\MigrationRepository;
use Pediacare\Services\MigrationService;
use PHPUnit\Framework\TestCase;

final class MigrationServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pediacare-migration-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory . '/000_create_migrations.sql', 'TRACKING_SQL');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*.sql') as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function test_T06_runs_pending_files_in_number_order_and_skips_applied_files(): void
    {
        file_put_contents($this->directory . '/003_third.sql', 'THIRD_SQL');
        file_put_contents($this->directory . '/001_first.sql', 'FIRST_SQL');
        file_put_contents($this->directory . '/002_second.sql', 'SECOND_SQL');
        $repository = $this->createMock(MigrationRepository::class);
        $repository->method('applied')->willReturn(['000_create_migrations.sql', '001_first.sql']);
        $executed = [];
        $recorded = [];
        $repository->method('execute')->willReturnCallback(static function (string $sql) use (&$executed): void {
            $executed[] = $sql;
        });
        $repository->method('record')->willReturnCallback(static function (string $filename) use (&$recorded): void {
            $recorded[] = $filename;
        });

        $completed = (new MigrationService($repository))->run($this->directory);

        self::assertSame(['TRACKING_SQL', 'SECOND_SQL', 'THIRD_SQL'], $executed);
        self::assertSame(['002_second.sql', '003_third.sql'], $recorded);
        self::assertSame($recorded, $completed);
    }

    public function test_T06_new_tracking_table_is_itself_recorded_once(): void
    {
        $repository = $this->createMock(MigrationRepository::class);
        $repository->method('applied')->willReturn([]);
        $repository->expects(self::once())->method('execute')->with('TRACKING_SQL');
        $repository->expects(self::once())->method('record')->with('000_create_migrations.sql');

        self::assertSame(['000_create_migrations.sql'], (new MigrationService($repository))->run($this->directory));
    }

    public function test_T06_failed_sql_is_not_recorded_and_later_files_are_not_run(): void
    {
        file_put_contents($this->directory . '/001_broken.sql', 'BROKEN_SQL');
        file_put_contents($this->directory . '/002_later.sql', 'LATER_SQL');
        $repository = $this->createMock(MigrationRepository::class);
        $repository->method('applied')->willReturn(['000_create_migrations.sql']);
        $repository->expects(self::exactly(2))->method('execute')->willReturnCallback(static function (string $sql): void {
            if ($sql === 'BROKEN_SQL') {
                throw new PDOException('FAKE_SQL_FAILURE');
            }
        });
        $repository->expects(self::never())->method('record');
        $this->expectException(PDOException::class);

        (new MigrationService($repository))->run($this->directory);
    }

    public function test_T06_duplicate_numbers_are_rejected_before_sql_runs(): void
    {
        file_put_contents($this->directory . '/001_first.sql', 'FIRST_SQL');
        file_put_contents($this->directory . '/001_duplicate.sql', 'DUPLICATE_SQL');
        $repository = $this->createMock(MigrationRepository::class);
        $repository->expects(self::never())->method('execute');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migration numbers must be unique.');

        (new MigrationService($repository))->run($this->directory);
    }

    public function test_T06_empty_sql_stops_without_recording_it(): void
    {
        file_put_contents($this->directory . '/001_empty.sql', '   ');
        $repository = $this->createMock(MigrationRepository::class);
        $repository->method('applied')->willReturn(['000_create_migrations.sql']);
        $repository->expects(self::never())->method('record');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Migration SQL is missing or empty.');

        (new MigrationService($repository))->run($this->directory);
    }
}
