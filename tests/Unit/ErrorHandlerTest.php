<?php

declare(strict_types=1);

use Pediacare\Core\ErrorHandler;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerTest extends TestCase
{
    public function test_T03_error_response_and_log_exclude_sensitive_exception_details(): void
    {
        $logPath = tempnam(sys_get_temp_dir(), 'pediacare-error-');
        self::assertIsString($logPath);
        $error = new RuntimeException('FAKE_CHILD_NAME FAKE_BIRTHDATE FAKE_PASSWORD');
        $previousStatus = http_response_code();

        ob_start();

        try {
            (new ErrorHandler($logPath))->handle($error);
            $output = ob_get_contents();
            $log = file_get_contents($logPath);

            self::assertSame(500, http_response_code());
            self::assertStringContainsString('Something went wrong', $output);
            self::assertStringNotContainsString($error->getMessage(), $output);
            self::assertStringNotContainsString('FAKE_', $log);
            $entry = json_decode($log, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(RuntimeException::class, $entry['type']);
            self::assertSame(basename(__FILE__), $entry['file']);
            self::assertSame($error->getLine(), $entry['line']);
        } finally {
            ob_end_clean();
            http_response_code(is_int($previousStatus) ? $previousStatus : 200);
            unlink($logPath);
        }
    }

    public function test_T03_php_warnings_are_converted_to_exceptions(): void
    {
        $displayErrors = (string) ini_get('display_errors');
        $logErrors = (string) ini_get('log_errors');
        $reporting = error_reporting(E_ALL);
        (new ErrorHandler(sys_get_temp_dir() . '/pediacare-unused.log'))->register();

        try {
            $this->expectException(ErrorException::class);
            trigger_error('FAKE_SENSITIVE_WARNING', E_USER_WARNING);
        } finally {
            restore_error_handler();
            restore_exception_handler();
            ini_set('display_errors', $displayErrors);
            ini_set('log_errors', $logErrors);
            error_reporting($reporting);
        }
    }

    public function test_T03_bootstrap_handles_an_uncaught_exception_without_exposing_its_message(): void
    {
        $projectPath = sys_get_temp_dir() . '/pediacare-bootstrap-' . bin2hex(random_bytes(8));
        mkdir($projectPath);

        foreach (['src', 'vendor', 'config', 'storage', 'storage/logs'] as $directory) {
            mkdir($projectPath . '/' . $directory);
        }

        copy(dirname(__DIR__, 2) . '/src/bootstrap.php', $projectPath . '/src/bootstrap.php');
        file_put_contents(
            $projectPath . '/vendor/autoload.php',
            "<?php\ndeclare(strict_types=1);\nrequire "
                . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
        );
        file_put_contents(
            $projectPath . '/config/config.local.php',
            "<?php\ndeclare(strict_types=1);\nreturn ['app' => ['timezone' => 'Asia/Manila']];\n"
        );
        file_put_contents(
            $projectPath . '/run.php',
            "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/src/bootstrap.php';\n"
                . "throw new RuntimeException('FAKE_CHILD_NAME FAKE_PASSWORD');\n"
        );

        try {
            $process = proc_open(
                [PHP_BINARY, $projectPath . '/run.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            self::assertSame(1, $exitCode, $output . $errors);
            self::assertStringContainsString('Something went wrong', $output);
            self::assertStringNotContainsString('FAKE_', $output);
            self::assertSame('', $errors);
            $log = file_get_contents($projectPath . '/storage/logs/app.log');
            self::assertStringContainsString('RuntimeException', $log);
            self::assertStringNotContainsString('FAKE_', $log);
        } finally {
            foreach (['run.php', 'src/bootstrap.php', 'vendor/autoload.php', 'config/config.local.php', 'storage/logs/app.log'] as $file) {
                if (is_file($projectPath . '/' . $file)) {
                    unlink($projectPath . '/' . $file);
                }
            }

            foreach (['storage/logs', 'storage', 'config', 'vendor', 'src'] as $directory) {
                rmdir($projectPath . '/' . $directory);
            }

            rmdir($projectPath);
        }
    }
}
