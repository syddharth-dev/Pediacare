<?php

declare(strict_types=1);

namespace Pediacare\Core;

use ErrorException;
use Throwable;

final class ErrorHandler
{
    public function __construct(private readonly string $logPath)
    {
    }

    public function register(): void
    {
        ini_set('display_errors', '0');
        // PHP's default logging can expose exception messages containing health data.
        ini_set('log_errors', '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $error): void {
            $this->handle($error);
            exit(1);
        });
    }

    public function handle(Throwable $error): void
    {
        $this->log($error);

        http_response_code(500);

        if (!headers_sent()) {
            SecurityHeaders::send();
            header('Content-Type: text/html; charset=UTF-8');
        }

        require dirname(__DIR__, 2) . '/views/errors/500.php';
    }

    public function log(Throwable $error): void
    {
        // Keep diagnostic location and type, never messages, traces, or request data.
        $entry = json_encode([
            'time' => gmdate('c'),
            'type' => get_class($error),
            'file' => basename($error->getFile()),
            'line' => $error->getLine(),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        @file_put_contents($this->logPath, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);

    }
}
