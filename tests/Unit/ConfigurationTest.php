<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Pediacare\Core\Container;

final class ConfigurationTest extends TestCase
{
    private string $projectPath;
    private string $timezone;
    private string $displayErrors;
    private string $logErrors;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        $this->displayErrors = (string) ini_get('display_errors');
        $this->logErrors = (string) ini_get('log_errors');
        $this->projectPath = sys_get_temp_dir() . '/pediacare-config-' . bin2hex(random_bytes(8));
        mkdir($this->projectPath);
        mkdir($this->projectPath . '/src');
        mkdir($this->projectPath . '/config');
        mkdir($this->projectPath . '/vendor');
        file_put_contents(
            $this->projectPath . '/vendor/autoload.php',
            "<?php\ndeclare(strict_types=1);\n"
        );
        copy(dirname(__DIR__, 2) . '/src/bootstrap.php', $this->projectPath . '/src/bootstrap.php');
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        date_default_timezone_set($this->timezone);
        ini_set('display_errors', $this->displayErrors);
        ini_set('log_errors', $this->logErrors);
        $localPath = $this->projectPath . '/config/config.local.php';

        if (is_file($localPath)) {
            unlink($localPath);
        }

        unlink($this->projectPath . '/src/bootstrap.php');
        unlink($this->projectPath . '/vendor/autoload.php');
        rmdir($this->projectPath . '/src');
        rmdir($this->projectPath . '/config');
        rmdir($this->projectPath . '/vendor');
        rmdir($this->projectPath);
    }

    public function test_T02_loads_local_configuration(): void
    {
        file_put_contents(
            $this->projectPath . '/config/config.local.php',
            "<?php\ndeclare(strict_types=1);\nreturn ['database' => ['name' => 'pediacare_test']];\n"
        );

        $container = require $this->projectPath . '/src/bootstrap.php';

        self::assertInstanceOf(Container::class, $container);
        self::assertSame(['database' => ['name' => 'pediacare_test']], $container->config);
        self::assertSame('Asia/Manila', date_default_timezone_get());
        self::assertSame('0', ini_get('display_errors'));
        self::assertSame('0', ini_get('log_errors'));
    }

    public function test_T02_missing_local_configuration_requires_setup(): void
    {
        copy(
            dirname(__DIR__, 2) . '/config/config.example.php',
            $this->projectPath . '/config/config.example.php'
        );

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Local configuration is missing. Follow the setup steps in README.md.');

            require $this->projectPath . '/src/bootstrap.php';
        } finally {
            unlink($this->projectPath . '/config/config.example.php');
        }
    }

    public function test_T02_rejects_configuration_that_does_not_return_an_array(): void
    {
        file_put_contents(
            $this->projectPath . '/config/config.local.php',
            "<?php\ndeclare(strict_types=1);\nreturn 'FAKE_CONFIG_VALUE';\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Local configuration must return an array.');

        require $this->projectPath . '/src/bootstrap.php';
    }
}
