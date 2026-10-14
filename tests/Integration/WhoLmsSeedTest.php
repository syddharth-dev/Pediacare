<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Repositories\WhoLmsRepository;
use Pediacare\Services\MigrationService;
use Pediacare\Services\WhoLmsSeedService;
use PHPUnit\Framework\TestCase;

final class WhoLmsSeedTest extends TestCase
{
    private PDO $pdo;
    private array $before;
    private array $config;

    protected function setUp(): void
    {
        $path = getenv('PEDIACARE_TEST_CONFIG') ?: dirname(__DIR__, 2) . '/config/config.local.php';
        if (!is_file($path)) {
            self::markTestSkipped('Configure pediacare_test to run MySQL integration tests.');
        }
        $config = (require $path)['database'];
        self::assertSame('pediacare_test', $config['test_name']);
        self::assertNotSame($config['name'], $config['test_name']);
        $this->config = $config;
        $config['name'] = $config['test_name'];
        $this->pdo = Database::connect($config);
        (new MigrationService(new MigrationRepository($this->pdo)))->run(dirname(__DIR__, 2) . '/database/migrations');
        $this->before = $this->rows();
    }

    protected function tearDown(): void
    {
        if (!isset($this->before)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo->beginTransaction();
        $this->pdo->prepare('DELETE FROM who_lms')->execute();
        $statement = $this->pdo->prepare(
            'INSERT INTO who_lms (id, reference, indicator, sex, age_or_size, l, m, s)
             VALUES (:id, :reference, :indicator, :sex, :age_or_size, :l, :m, :s)'
        );
        foreach ($this->before as $row) {
            $statement->execute($row);
        }
        $this->pdo->commit();
    }

    private function rows(): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM who_lms ORDER BY reference, indicator, sex, age_or_size');
        $statement->execute();

        return $statement->fetchAll();
    }

    private function service(?WhoLmsRepository $repository = null): WhoLmsSeedService
    {
        return new WhoLmsSeedService($this->pdo, $repository ?? new WhoLmsRepository($this->pdo));
    }

    public function test_AC06_official_tables_persist_exact_parameters_and_rerun_keeps_ids_and_row_counts(): void
    {
        $directory = dirname(__DIR__, 2) . '/database/who';
        self::assertSame(14344, $this->service()->run($directory));
        $table = (new WhoLmsRepository($this->pdo))->load();
        $calculator = new \Pediacare\Domain\Growth\GrowthCalculator(
            new \Pediacare\Domain\Growth\Reference\Who2006Standard($table),
            new \Pediacare\Domain\Growth\Reference\Who2007Reference($table),
        );
        $birth = new DateTimeImmutable('2000-01-01');
        foreach (json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/who-anthro-growth.json'), true, 512, JSON_THROW_ON_ERROR) as $case) {
            $result = $calculator->calculate(
                \Pediacare\Domain\Enums\Sex::from($case['sex']),
                \Pediacare\Domain\Growth\AgeInMonths::between($birth, $birth->modify('+' . $case['days'] . ' days')),
                $case['weight_kg'], $case['height_cm'], \Pediacare\Domain\Enums\MeasurementPosition::from($case['position']),
            );
            foreach (['wfa_z' => 'wfaZ', 'hfa_z' => 'hfaZ', 'wfh_z' => 'wfhZ', 'bmi_z' => 'bmiZ'] as $key => $property) {
                if ($case[$key] === null) {
                    self::assertNull($result->$property);
                } else {
                    self::assertEqualsWithDelta($case[$key], $result->$property, 0.01);
                }
            }
        }
        $rows = $this->rows();
        self::assertCount(14344, $rows);
        $expected = [];
        foreach (['who2006.csv', 'who2007.csv'] as $file) {
            $stream = fopen($directory . '/' . $file, 'rb');
            fgetcsv($stream);
            while (($values = fgetcsv($stream)) !== false) {
                $key = implode(':', array_slice($values, 0, 4));
                $expected[$key] = array_map(static fn (string $value): string => number_format((float) $value, 6, '.', ''), array_slice($values, 4));
            }
            fclose($stream);
        }
        $actual = [];
        foreach ($rows as $row) {
            $key = $row['reference'] . ':' . $row['indicator'] . ':' . $row['sex'] . ':' . $row['age_or_size'];
            $actual[$key] = [$row['l'], $row['m'], $row['s']];
        }
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
        self::assertSame(14344, $this->service()->run($directory));
        self::assertSame($rows, $this->rows());
        self::assertFalse($this->pdo->inTransaction());
        $statement = $this->pdo->prepare('SHOW INDEX FROM who_lms WHERE Key_name = :name');
        $statement->execute(['name' => 'who_lms_lookup']);
        $index = $statement->fetchAll();
        self::assertSame(['reference', 'indicator', 'sex', 'age_or_size'], array_column($index, 'Column_name'));
        self::assertSame([0, 0, 0, 0], array_map('intval', array_column($index, 'Non_unique')));
    }

    public function test_AC06_mid_import_sql_failure_rolls_back_inserts_and_updates(): void
    {
        $this->pdo->beginTransaction();
        $real = new WhoLmsRepository($this->pdo);
        $real->upsert(['reference' => 'WHO2006', 'indicator' => 'WFA', 'sex' => 'M',
            'age_or_size' => '0.00', 'l' => '0', 'm' => '1', 's' => '1']);
        $this->pdo->commit();
        $before = $this->rows();
        $repository = new class ($this->pdo) extends WhoLmsRepository {
            private int $count = 0;

            public function upsert(array $row): void
            {
                parent::upsert($row);
                if (++$this->count === 3) {
                    throw new RuntimeException('FAKE failure after SQL writes');
                }
            }
        };
        try {
            $this->service($repository)->run(dirname(__DIR__, 2) . '/database/who');
            self::fail('The injected import failure must be reported.');
        } catch (RuntimeException $error) {
            self::assertSame('FAKE failure after SQL writes', $error->getMessage());
        }
        self::assertSame($before, $this->rows());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function test_AC06_database_rejects_duplicate_coordinates_and_nonpositive_parameters(): void
    {
        $this->pdo->beginTransaction();
        $statement = $this->pdo->prepare(
            'INSERT INTO who_lms (reference, indicator, sex, age_or_size, l, m, s)
             VALUES (:reference, :indicator, :sex, :age_or_size, :l, :m, :s)'
        );
        $row = ['reference' => 'WHO2006', 'indicator' => 'WFA', 'sex' => 'M',
            'age_or_size' => '99999.99', 'l' => '0', 'm' => '1', 's' => '1'];
        $statement->execute($row);
        foreach ([$row, [...$row, 'age_or_size' => '99998.99', 'm' => '0'],
            [...$row, 'age_or_size' => '99997.99', 's' => '-1']] as $invalid) {
            try {
                $statement->execute($invalid);
                self::fail('The database constraint must reject invalid reference rows.');
            } catch (PDOException $error) {
                self::assertNotEmpty($error->getCode());
            }
        }
        $this->pdo->rollBack();
        self::assertSame($this->before, $this->rows());
    }

    public function test_T21_cli_imports_offline_into_test_database_and_refuses_same_database_configuration(): void
    {
        $directory = sys_get_temp_dir() . '/pediacare-who-cli-' . bin2hex(random_bytes(8));
        foreach (['bin', 'src', 'vendor', 'database/who', 'storage/logs'] as $folder) {
            mkdir($directory . '/' . $folder, 0777, true);
        }
        copy(dirname(__DIR__, 2) . '/bin/seed-who.php', $directory . '/bin/seed-who.php');
        file_put_contents($directory . '/vendor/autoload.php', "<?php\ndeclare(strict_types=1);\nrequire_once "
            . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n");
        foreach (['who2006.csv', 'who2007.csv', 'manifest.json'] as $file) {
            copy(dirname(__DIR__, 2) . '/database/who/' . $file, $directory . '/database/who/' . $file);
        }
        try {
            foreach ([false, true] as $sameDatabase) {
                $config = $this->config;
                if ($sameDatabase) {
                    $config['test_name'] = $config['name'];
                }
                file_put_contents($directory . '/src/bootstrap.php', "<?php\ndeclare(strict_types=1);\nreturn new \\Pediacare\\Core\\Container("
                    . var_export(['database' => $config], true) . ");\n");
                $before = $this->rows();
                $process = proc_open([PHP_BINARY, $directory . '/bin/seed-who.php', '--test'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame($sameDatabase ? 1 : 0, proc_close($process));
                if ($sameDatabase) {
                    self::assertStringContainsString('WHO import failed.', $stderr);
                    self::assertSame('', $stdout);
                    self::assertSame($before, $this->rows());
                } else {
                    self::assertStringContainsString('14344 reference rows processed.', $stdout);
                    self::assertSame('', $stderr);
                    self::assertCount(14344, $this->rows());
                }
            }
        } finally {
            foreach (['bin/seed-who.php', 'src/bootstrap.php', 'vendor/autoload.php',
                'database/who/who2006.csv', 'database/who/who2007.csv', 'database/who/manifest.json',
                'storage/logs/app.log'] as $file) {
                if (is_file($directory . '/' . $file)) {
                    unlink($directory . '/' . $file);
                }
            }
            foreach (['bin', 'src', 'vendor', 'database/who', 'database', 'storage/logs', 'storage'] as $folder) {
                rmdir($directory . '/' . $folder);
            }
            rmdir($directory);
        }
    }
}
