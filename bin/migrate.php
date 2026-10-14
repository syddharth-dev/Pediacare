<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Core\ErrorHandler;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Services\MigrationService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$arguments = array_slice($argv, 1);

if ($arguments === ['--help']) {
    fwrite(STDOUT, "Usage: php bin/migrate.php [--test]\n");
    exit(0);
}

if ($arguments !== [] && $arguments !== ['--test']) {
    fwrite(STDERR, "Usage: php bin/migrate.php [--test]\n");
    exit(2);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $container = require dirname(__DIR__) . '/src/bootstrap.php';
    $config = $container->config['database'];

    if ($arguments === ['--test']) {
        $testName = $config['test_name'] ?? null;

        if (!is_string($testName) || $testName === '' || $testName === $config['name']) {
            throw new RuntimeException('A separate test database is required.');
        }

        $config['name'] = $testName;
    }

    $pdo = Database::connect($config);
    $service = new MigrationService(new MigrationRepository($pdo));
    $completed = $service->run(dirname(__DIR__) . '/database/migrations');

    foreach ($completed as $filename) {
        fwrite(STDOUT, 'Applied ' . $filename . PHP_EOL);
    }

    fwrite(STDOUT, $completed === [] ? "No pending migrations.\n" : "Migrations complete.\n");
} catch (Throwable $error) {
    (new ErrorHandler(dirname(__DIR__) . '/storage/logs/app.log'))->log($error);
    fwrite(STDERR, "Migration failed. Check local configuration, database access, and storage/logs/app.log.\n"
        . "Earlier schema changes may already be committed; review the schema before retrying.\n");
    exit(1);
}
