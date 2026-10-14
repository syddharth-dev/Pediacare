<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Core\ErrorHandler;
use Pediacare\Repositories\VaccineScheduleRepository;
use Pediacare\Services\VaccineScheduleSeedService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$arguments = array_slice($argv, 1);
if ($arguments === ['--help']) {
    fwrite(STDOUT, "Usage: php bin/seed-vaccines.php [--test]\nSeeds the DRAFT vaccine schedule without overwriting existing entries.\n");
    exit(0);
}
if ($arguments !== [] && $arguments !== ['--test']) {
    fwrite(STDERR, "Usage: php bin/seed-vaccines.php [--test]\n");
    exit(2);
}
require_once dirname(__DIR__) . '/vendor/autoload.php';
try {
    $container = require dirname(__DIR__) . '/src/bootstrap.php';
    $config = $container->config['database'];
    if ($arguments === ['--test']) {
        if (($config['test_name'] ?? null) !== 'pediacare_test' || $config['test_name'] === $config['name']) {
            throw new RuntimeException('A separate pediacare_test database is required.');
        }
        $config['name'] = $config['test_name'];
    }
    $pdo = Database::connect($config);
    $count = (new VaccineScheduleSeedService($pdo, new VaccineScheduleRepository($pdo)))->run(
        require dirname(__DIR__) . '/database/seeds/vaccine-schedule.php'
    );
    fwrite(STDOUT, "DRAFT vaccine schedule: $count doses processed; existing entries preserved.\nConfirm with the center before live use.\n");
} catch (Throwable $error) {
    (new ErrorHandler(dirname(__DIR__) . '/storage/logs/app.log'))->log($error);
    fwrite(STDERR, "Vaccine seed failed. Check migrations, local configuration, and storage/logs/app.log.\n");
    exit(1);
}
