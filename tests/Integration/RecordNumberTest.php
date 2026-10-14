<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Repositories\RecordCounterRepository;
use Pediacare\Services\MigrationService;
use Pediacare\Support\RecordNumberGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecordNumberTest extends TestCase
{
    private PDO $pdo;
    private array $config;
    private int $year;
    private int $otherYear;
    private RecordNumberGenerator $generator;

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
        $this->year = $this->unusedYear();
        do {
            $this->otherYear = $this->unusedYear();
        } while ($this->otherYear === $this->year);
        $this->generator = new RecordNumberGenerator(new RecordCounterRepository($this->pdo));
    }

    private function unusedYear(): int
    {
        do {
            $year = random_int(8000, 9000);
        } while ($this->lastNumber($year) !== null);

        return $year;
    }

    private function lastNumber(int $year): ?int
    {
        $statement = $this->pdo->prepare('SELECT last_number FROM record_counters WHERE year = :year');
        $statement->execute(['year' => $year]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        foreach (['year', 'otherYear'] as $property) {
            if (isset($this->$property)) {
                $statement = $this->pdo->prepare('DELETE FROM record_counters WHERE year = :year');
                $statement->execute(['year' => $this->$property]);
            }
        }
    }

    public function test_AC05_sequence_persists_across_transactions_and_years_are_independent(): void
    {
        $this->pdo->beginTransaction();
        self::assertSame(sprintf('PC-%04d-00001', $this->year), $this->generator->generate($this->year));
        self::assertSame(sprintf('PC-%04d-00002', $this->year), $this->generator->generate($this->year));
        self::assertSame(sprintf('PC-%04d-00001', $this->otherYear), $this->generator->generate($this->otherYear));
        self::assertTrue($this->pdo->inTransaction());
        $this->pdo->commit();
        $this->pdo->beginTransaction();
        self::assertSame(sprintf('PC-%04d-00003', $this->year), $this->generator->generate($this->year));
        $this->pdo->commit();
        self::assertSame(3, $this->lastNumber($this->year));
        self::assertSame(1, $this->lastNumber($this->otherYear));
    }

    public function test_AC05_rollback_reverts_counter_initialization_and_existing_counter_increment(): void
    {
        $this->pdo->beginTransaction();
        $first = $this->generator->generate($this->year);
        $this->pdo->rollBack();
        self::assertNull($this->lastNumber($this->year));
        $this->pdo->beginTransaction();
        self::assertSame($first, $this->generator->generate($this->year));
        $this->pdo->commit();
        $this->pdo->beginTransaction();
        $second = $this->generator->generate($this->year);
        $this->pdo->rollBack();
        self::assertSame(1, $this->lastNumber($this->year));
        $this->pdo->beginTransaction();
        self::assertSame($second, $this->generator->generate($this->year));
    }

    public function test_AC05_autocommit_generation_is_rejected_without_creating_counter(): void
    {
        try {
            $this->generator->generate($this->year);
            self::fail('Autocommit generation was allowed.');
        } catch (LogicException $error) {
            self::assertNull($this->lastNumber($this->year));
        }
    }

    public function test_AC05_autocommit_counter_save_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        (new RecordCounterRepository($this->pdo))->save($this->year, 1);
    }

    public function test_AC05_last_five_digit_number_is_issued_once_then_exhaustion_does_not_change_counter(): void
    {
        $this->pdo->beginTransaction();
        $statement = $this->pdo->prepare('INSERT INTO record_counters (year, last_number) VALUES (:year, :number)');
        $statement->execute(['year' => $this->year, 'number' => 99998]);
        self::assertSame(sprintf('PC-%04d-99999', $this->year), $this->generator->generate($this->year));
        try {
            $this->generator->generate($this->year);
            self::fail('Exhaustion was ignored.');
        } catch (OverflowException $error) {
            self::assertSame(99999, $this->lastNumber($this->year));
            self::assertTrue($this->pdo->inTransaction());
        }
    }

    #[DataProvider('counterStates')]
    public function test_AC05_concurrent_allocations_wait_and_return_distinct_numbers(bool $existing): void
    {
        if ($existing) {
            $statement = $this->pdo->prepare('INSERT INTO record_counters (year) VALUES (:year)');
            $statement->execute(['year' => $this->year]);
        }
        $this->pdo->beginTransaction();
        $first = $this->generator->generate($this->year);
        $directory = sys_get_temp_dir() . '/pediacare-counter-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $worker = $directory . '/worker.php';
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $script = <<<'PHP'
<?php
declare(strict_types=1);
require AUTOLOAD;
use Pediacare\Core\Database;
use Pediacare\Repositories\RecordCounterRepository;
use Pediacare\Support\RecordNumberGenerator;
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$pdo = Database::connect($input['config']);
$pdo->beginTransaction();
echo "READY\n";
flush();
try {
    $number = (new RecordNumberGenerator(new RecordCounterRepository($pdo)))->generate($input['year']);
    $pdo->commit();
    file_put_contents($input['done'], $number);
    echo $number . "\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Counter worker failed.');
    exit(1);
}
PHP;
        $workers = [];
        try {
            file_put_contents($worker, str_replace('AUTOLOAD', $autoload, $script));
            for ($index = 0; $index < 2; $index++) {
                $process = proc_open([PHP_BINARY, $worker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
                fwrite($pipes[0], json_encode(['config' => $this->config, 'year' => $this->year,
                    'done' => $directory . '/done-' . $index], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 10);
                self::assertSame("READY\n", fgets($pipes[1]));
            }
            // Windows process pipes cannot reliably signal readiness through stream_select.
            usleep(200000);
            foreach ([0, 1] as $index) {
                self::assertFileDoesNotExist($directory . '/done-' . $index, 'Allocation completed before the lock was released.');
            }
            $this->pdo->commit();
            $numbers = [$first];
            foreach ($workers as $item) {
                $numbers[] = trim((string) fgets($item['pipes'][1]));
            }
            sort($numbers);
            self::assertSame([
                sprintf('PC-%04d-00001', $this->year), sprintf('PC-%04d-00002', $this->year), sprintf('PC-%04d-00003', $this->year),
            ], $numbers);
            self::assertSame(3, $this->lastNumber($this->year));
            foreach ($workers as $item) {
                self::assertSame('', stream_get_contents($item['pipes'][2]));
                fclose($item['pipes'][1]);
                fclose($item['pipes'][2]);
                self::assertSame(0, proc_close($item['process']));
            }
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            foreach ($workers as $item) {
                if (is_resource($item['process'])) {
                    proc_terminate($item['process']);
                    foreach ($item['pipes'] as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($item['process']);
                }
            }
            if (is_file($worker)) {
                unlink($worker);
            }
            foreach ([0, 1] as $index) {
                if (is_file($directory . '/done-' . $index)) {
                    unlink($directory . '/done-' . $index);
                }
            }
            rmdir($directory);
        }
    }

    public static function counterStates(): array
    {
        return [[false], [true]];
    }
}
