<?php

declare(strict_types=1);

use Pediacare\Repositories\WhoLmsRepository;
use Pediacare\Services\WhoLmsSeedService;
use PHPUnit\Framework\TestCase;

final class WhoLmsDataTest extends TestCase
{
    public function test_AC06_bundled_reference_data_has_complete_sex_indicator_grids_and_source_checkpoints(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $repository = $this->createMock(WhoLmsRepository::class);
        $grids = [];
        $checkpoints = [];
        $repository->method('upsert')->willReturnCallback(static function (array $row) use (&$grids, &$checkpoints): void {
            $key = $row['reference'] . ':' . $row['indicator'] . ':' . $row['sex'];
            $grids[$key][] = $row['age_or_size'];
            $checkpoints[$key . ':' . $row['age_or_size']] = [$row['l'], $row['m'], $row['s']];
        });
        self::assertSame(14344, (new WhoLmsSeedService($pdo, $repository))->run(dirname(__DIR__, 2) . '/database/who'));
        self::assertCount(16, $grids);
        foreach (['M', 'F'] as $sex) {
            foreach (['WFA', 'HFA', 'BFA'] as $indicator) {
                $expected = array_map(static fn (int $age): string => number_format($age, 2, '.', ''), range(0, 1856));
                self::assertSame($expected, $grids['WHO2006:' . $indicator . ':' . $sex]);
                $end = $indicator === 'WFA' ? 121 : 229;
                $expected = array_map(static fn (int $age): string => number_format($age, 2, '.', ''), range(61, $end));
                self::assertSame($expected, $grids['WHO2007:' . $indicator . ':' . $sex]);
            }
            foreach (['WFL' => [450, 1100], 'WFH' => [650, 1200]] as $indicator => [$min, $max]) {
                $expected = array_map(static fn (int $size): string => number_format($size / 10, 2, '.', ''), range($min, $max));
                self::assertSame($expected, $grids['WHO2006:' . $indicator . ':' . $sex]);
            }
        }
        // Original WHO SPSS archive checkpoints; sources and conversion are documented in database/who/README.md.
        $expected = [
            'WHO2006:WFA:M:0.00' => ['0.3487', '3.3464', '0.14602'],
            'WHO2006:WFA:F:1856.00' => ['-0.3531', '18.3890', '0.14892'],
            'WHO2006:HFA:M:0.00' => ['1.0000', '49.8842', '0.03795'],
            'WHO2006:HFA:F:1856.00' => ['1.0000', '109.9352', '0.04358'],
            'WHO2006:BFA:M:0.00' => ['-0.3053', '13.4069', '0.09560'],
            'WHO2006:BFA:F:1856.00' => ['-0.5684', '15.2798', '0.09825'],
            'WHO2006:WFL:M:45.00' => ['-0.3521', '2.4410', '0.09182'],
            'WHO2006:WFL:F:110.00' => ['-0.3833', '18.3324', '0.09401'],
            'WHO2006:WFH:M:65.00' => ['-0.3521', '7.4327', '0.08217'],
            'WHO2006:WFH:F:120.00' => ['-0.3833', '22.8173', '0.09828'],
            'WHO2007:WFA:M:61.00' => ['-0.2026', '18.5057', '0.12988'],
            'WHO2007:WFA:F:121.00' => ['-0.5958', '31.8578', '0.17262'],
            'WHO2007:HFA:M:61.00' => ['1.0000', '110.2647', '0.04164'],
            'WHO2007:HFA:F:229.00' => ['1.0000', '163.1548', '0.04009'],
            'WHO2007:BFA:M:61.00' => ['-0.7387', '15.2641', '0.08390'],
            'WHO2007:BFA:F:229.00' => ['-0.7496', '21.4269', '0.14441'],
        ];
        foreach ($expected as $key => $values) {
            self::assertSame($values, $checkpoints[$key], $key);
        }
    }

    public function test_T21_cli_help_and_invalid_arguments_do_not_require_local_configuration(): void
    {
        foreach ([['--help', 0, 'Loads the bundled WHO LMS'], ['--unknown', 2, 'Usage:']] as [$argument, $exitCode, $message]) {
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/seed-who.php', $argument],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame($exitCode, proc_close($process));
            self::assertStringContainsString($message, $output);
        }
    }
}
