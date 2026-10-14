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
    fwrite(STDOUT, "Usage: php bin/restore.php <encrypted-backup> <empty-database>\n"
        . "Authenticates the backup before restoring into a separate empty database.\n");
    exit(0);
}
if (count($arguments) !== 2 || str_starts_with($arguments[0], '--')
    || preg_match('/\A[a-zA-Z0-9_]+\z/', $arguments[1]) !== 1) {
    fwrite(STDERR, "Usage: php bin/restore.php <encrypted-backup> <empty-database>\n");
    exit(2);
}
require_once dirname(__DIR__) . '/vendor/autoload.php';
try {
    $container = require dirname(__DIR__) . '/src/bootstrap.php';
    $database = $container->config['database'];
    $options = $container->config['backup'] ?? [];
    $service = new BackupService(new BackupRepository($database, $options['mysqldump'] ?? '',
        $options['mysql'] ?? '', $options['directory'] ?? ''), new BackupCipher($options['key'] ?? ''),
        $options['directory'] ?? '', dirname(__DIR__), array_filter([$database['name'], $database['test_name'] ?? null]));
    $service->restore($arguments[0], $arguments[1]);
    fwrite(STDOUT, "Backup authenticated and restored. Verify the recovery database before switching the application.\n");
} catch (Throwable $error) {
    (new ErrorHandler(dirname(__DIR__) . '/storage/logs/app.log'))->log($error);
    fwrite(STDERR, "Restore failed. Check the backup key, file, clients and separate empty database.\n"
        . "A failed database import may leave partial tables; inspect the recovery database before retrying.\n");
    exit(1);
}
