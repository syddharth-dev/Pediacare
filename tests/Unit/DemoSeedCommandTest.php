<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DemoSeedCommandTest extends TestCase
{
    #[DataProvider('arguments')]
    public function test_T39_demo_command_requires_explicit_demo_flag_before_loading_configuration(array $arguments, int $status): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/seed-demo.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame($status, proc_close($process));
        self::assertStringContainsString('Usage: php bin/seed-demo.php --demo [--test]', $status === 0 ? $stdout : $stderr);
        self::assertSame('', $status === 0 ? $stderr : $stdout);
        if ($status === 0) {
            self::assertStringContainsString('200 FAKE children', $stdout);
        }
    }

    public static function arguments(): array
    {
        return [
            'help' => [['--help'], 0], 'no arguments' => [[], 2], 'test without demo' => [['--test'], 2],
            'unknown' => [['--unknown'], 2], 'extra' => [['--demo', '--test', '--extra'], 2],
            'duplicate flag' => [['--demo', '--demo'], 2],
        ];
    }
}
