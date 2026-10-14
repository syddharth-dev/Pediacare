<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Core\ErrorHandler;
use Pediacare\Repositories\WhoLmsRepository;
use Pediacare\Services\WhoLmsSeedService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$arguments = array_slice($argv, 1);
if ($arguments === ['--help']) {
    fwrite(STDOUT, "Usage: php bin/seed-who.php [--test]\nLoads the bundled WHO LMS reference data.\n");
    exit(0);
}
if ($arguments !== [] && $arguments !== ['--test']) {
    fwrite(STDERR, "Usage: php bin/seed-who.php [--test]\n");
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
    $service = new WhoLmsSeedService($pdo, new WhoLmsRepository($pdo));
    $count = $service->run(dirname(__DIR__) . '/database/who');
    fwrite(STDOUT, 'WHO LMS import complete: ' . $count . " reference rows processed.\n");
} catch (Throwable $error) {
    (new ErrorHandler(dirname(__DIR__) . '/storage/logs/app.log'))->log($error);
    fwrite(STDERR, "WHO import failed. Check migrations, reference files, local configuration, and storage/logs/app.log.\n");
    exit(1);
}
