<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupCommandTest extends TestCase
{
    #[DataProvider('arguments')]
    public function test_T40_help_and_invalid_arguments_do_not_load_local_configuration(string $script, array $arguments, int $status): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/' . $script, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame($status, proc_close($process));
        self::assertStringContainsString('Usage: php bin/' . $script, $status === 0 ? $stdout : $stderr);
        self::assertSame('', $status === 0 ? $stderr : $stdout);
    }

    public static function arguments(): array
    {
        return [
            ['backup.php', ['--help'], 0], ['backup.php', ['--unknown'], 2],
            ['backup.php', ['--test', '--test'], 2], ['restore.php', ['--help'], 0],
            ['restore.php', [], 2], ['restore.php', ['FAKE.pcbackup'], 2],
            ['restore.php', ['FAKE.pcbackup', '../invalid'], 2],
            ['restore.php', ['FAKE.pcbackup', 'fake; DROP DATABASE fake'], 2],
        ];
    }
}
