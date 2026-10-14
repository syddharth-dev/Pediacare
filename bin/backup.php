<?php

declare(strict_types=1);

use Pediacare\Core\ErrorHandler;
use Pediacare\Repositories\BackupRepository;
use Pediacare\Services\BackupService;
use Pediacare\Support\BackupCipher;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$arguments = array_slice($argv, 1);
if ($arguments === ['--help']) {
    fwrite(STDOUT, "Usage: php bin/backup.php [--test]\nCreates an authenticated encrypted mysqldump backup.\n");
    exit(0);
}
if ($arguments !== [] && $arguments !== ['--test']) {
    fwrite(STDERR, "Usage: php bin/backup.php [--test]\n");
    exit(2);
}
require_once dirname(__DIR__) . '/vendor/autoload.php';
try {
    $container = require dirname(__DIR__) . '/src/bootstrap.php';
    $database = $container->config['database'];
    if ($arguments === ['--test']) {
        if (($database['test_name'] ?? null) !== 'pediacare_test' || $database['test_name'] === $database['name']) {
            throw new RuntimeException('A separate pediacare_test database is required.');
        }
        $database['name'] = $database['test_name'];
    }
    $options = $container->config['backup'] ?? [];
    $service = new BackupService(new BackupRepository($database, $options['mysqldump'] ?? '',
        $options['mysql'] ?? '', $options['directory'] ?? ''), new BackupCipher($options['key'] ?? ''),
        $options['directory'] ?? '', dirname(__DIR__));
    fwrite(STDOUT, 'Encrypted backup created: ' . $service->backup() . PHP_EOL);
} catch (Throwable $error) {
    (new ErrorHandler(dirname(__DIR__) . '/storage/logs/app.log'))->log($error);
    fwrite(STDERR, "Backup failed. Check private backup configuration, client executables, database access and storage/logs/app.log.\n");
    exit(1);
}
