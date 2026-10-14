<?php

declare(strict_types=1);

use Pediacare\Repositories\WhoLmsRepository;
use Pediacare\Services\WhoLmsSeedService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhoLmsSeedServiceTest extends TestCase
{
    private string $directory;
    private PDO $pdo;
    private WhoLmsRepository $repository;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pediacare-who-unit-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->pdo = $this->createMock(PDO::class);
        $this->repository = $this->createMock(WhoLmsRepository::class);
        $this->files();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function files(string $first = 'WHO2006,WFA,M,0.00,0.3487,3.3464,0.14602'): void
    {
        $header = "reference,indicator,sex,age_or_size,l,m,s\n";
        // Synthetic reference-file fixtures contain no child data.
        file_put_contents($this->directory . '/who2006.csv', $header . $first . "\n");
        file_put_contents($this->directory . '/who2007.csv', $header . "WHO2007,HFA,F,61.00,1.0000,109.6016,0.04355\n");
        $this->manifest();
    }

    private function manifest(): void
    {
        $manifest = [];
        foreach (['who2006.csv', 'who2007.csv'] as $name) {
            $content = file_get_contents($this->directory . '/' . $name);
            $manifest[$name] = ['rows' => substr_count($content, "\n") - 1, 'sha256' => hash('sha256', $content)];
        }
        file_put_contents($this->directory . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    public function test_AC06_import_preserves_decimal_strings_and_commits_both_files_together(): void
    {
        $this->pdo->method('inTransaction')->willReturn(false);
        $this->pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $this->pdo->expects(self::once())->method('commit')->willReturn(true);
        $this->pdo->expects(self::never())->method('rollBack');
        $rows = [];
        $this->repository->expects(self::exactly(2))->method('upsert')->willReturnCallback(
            static function (array $row) use (&$rows): void {
                $rows[] = $row;
            }
        );
        self::assertSame(2, (new WhoLmsSeedService($this->pdo, $this->repository))->run($this->directory));
        self::assertSame('0.3487', $rows[0]['l']);
        self::assertSame('0.00', $rows[0]['age_or_size']);
        self::assertSame('WHO2007', $rows[1]['reference']);
    }

    public function test_AC06_failed_second_file_write_rolls_back_without_commit(): void
    {
        $this->pdo->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $this->pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $this->pdo->expects(self::never())->method('commit');
        $this->pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $calls = 0;
        $this->repository->method('upsert')->willReturnCallback(static function () use (&$calls): void {
            if (++$calls === 2) {
                throw new RuntimeException('FAKE import failure');
            }
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FAKE import failure');
        (new WhoLmsSeedService($this->pdo, $this->repository))->run($this->directory);
    }

    public function test_AC06_import_refuses_to_commit_or_roll_back_a_callers_transaction(): void
    {
        $this->pdo->method('inTransaction')->willReturn(true);
        $this->pdo->expects(self::never())->method('beginTransaction');
        $this->pdo->expects(self::never())->method('commit');
        $this->pdo->expects(self::never())->method('rollBack');
        $this->repository->expects(self::never())->method('upsert');
        $this->expectException(LogicException::class);
        (new WhoLmsSeedService($this->pdo, $this->repository))->run($this->directory);
    }

    #[DataProvider('invalidRows')]
    public function test_AC06_invalid_reference_rows_fail_before_any_database_write(string $row): void
    {
        $this->files($row);
        $this->pdo->expects(self::never())->method('beginTransaction');
        $this->repository->expects(self::never())->method('upsert');
        $this->expectException(RuntimeException::class);
        (new WhoLmsSeedService($this->pdo, $this->repository))->run($this->directory);
    }

    public static function invalidRows(): array
    {
        return [
            'wrong reference' => ['WHO2007,WFA,M,0.00,1,1,1'],
            'unknown indicator' => ['WHO2006,MUAC,M,0.00,1,1,1'],
            'unknown sex' => ['WHO2006,WFA,X,0.00,1,1,1'],
            'negative coordinate' => ['WHO2006,WFA,M,-1,1,1,1'],
            'coordinate exceeds column' => ['WHO2006,WFA,M,100000,1,1,1'],
            'coordinate precision' => ['WHO2006,WFA,M,1.001,1,1,1'],
            'parameter precision' => ['WHO2006,WFA,M,0,1.0000001,1,1'],
            'parameter exceeds column' => ['WHO2006,WFA,M,0,10000,1,1'],
            'zero median' => ['WHO2006,WFA,M,0,1,0,1'],
            'negative sigma' => ['WHO2006,WFA,M,0,1,1,-1'],
            'not a number' => ['WHO2006,WFA,M,0,NaN,1,1'],
            'exponent' => ['WHO2006,WFA,M,0,1e0,1,1'],
            'SQL injection' => ["WHO2006,WFA,M,0,1,1,1'); DROP TABLE children; --"],
            'missing value' => ['WHO2006,WFA,M,0,1,1,'],
            'extra column' => ['WHO2006,WFA,M,0,1,1,1,extra'],
            'duplicate numeric coordinate' => ["WHO2006,WFA,M,0.00,1,1,1\nWHO2006,WFA,M,0,1,1,1"],
        ];
    }

    #[DataProvider('damagedFiles')]
    public function test_AC06_missing_or_corrupt_files_fail_before_any_database_write(string $damage): void
    {
        switch ($damage) {
            case 'missing':
                unlink($this->directory . '/who2007.csv');
                break;
            case 'empty':
                file_put_contents($this->directory . '/who2006.csv', '');
                break;
            case 'checksum':
                file_put_contents($this->directory . '/who2007.csv', 'FAKE corrupt file');
                break;
            case 'header':
                $path = $this->directory . '/who2007.csv';
                file_put_contents($path, str_replace('age_or_size', 'age', file_get_contents($path)));
                $this->manifest();
                break;
            case 'count':
                $path = $this->directory . '/manifest.json';
                $manifest = json_decode(file_get_contents($path), true);
                $manifest['who2007.csv']['rows'] = 2;
                file_put_contents($path, json_encode($manifest));
                break;
            case 'manifest':
                file_put_contents($this->directory . '/manifest.json', '{}');
                break;
        }
        $this->pdo->expects(self::never())->method('beginTransaction');
        $this->repository->expects(self::never())->method('upsert');
        $this->expectException(RuntimeException::class);
        (new WhoLmsSeedService($this->pdo, $this->repository))->run($this->directory);
    }

    public static function damagedFiles(): array
    {
        return array_map(static fn (string $value): array => [$value], ['missing', 'empty', 'checksum', 'header', 'count', 'manifest']);
    }

    public function test_AC06_repository_requires_a_transaction_before_preparing_sql(): void
    {
        $this->pdo->method('inTransaction')->willReturn(false);
        $this->pdo->expects(self::never())->method('prepare');
        $this->expectException(LogicException::class);
        (new WhoLmsRepository($this->pdo))->upsert([]);
    }
}
