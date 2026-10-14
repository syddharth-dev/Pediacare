<?php

declare(strict_types=1);

use Pediacare\Core\{Database, ErrorHandler};
use Pediacare\Repositories\{DemoSeedRepository, PurokRepository, UserRepository, VaccineScheduleRepository, WhoLmsRepository};
use Pediacare\Services\{ChildService, DemoSeedService, ImmunizationService, MeasurementService, VisitService};

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$arguments = array_slice($argv, 1);
$usage = "Usage: php bin/seed-demo.php --demo [--test]\n";
if ($arguments === ['--help']) {
    fwrite(STDOUT, $usage . "Creates 200 FAKE children in an empty demo registry.\n"
        . "Requires migrations, WHO references, a vaccine schedule and an active Admin.\n"
        . "Existing accounts and records are preserved. Never run against live health data.\n");
    exit(0);
}
if (!in_array($arguments, [['--demo'], ['--demo', '--test'], ['--test', '--demo']], true)) {
    fwrite(STDERR, $usage);
    exit(2);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $container = require dirname(__DIR__) . '/src/bootstrap.php';
    if (in_array('--test', $arguments, true)) {
        $config = $container->config['database'];
        if (($config['test_name'] ?? null) !== 'pediacare_test' || $config['test_name'] === $config['name']) {
            throw new RuntimeException('A separate pediacare_test database is required.');
        }
        $config['name'] = $config['test_name'];
        $container->register(PDO::class, static fn (): PDO => Database::connect($config));
    }
    // Confirmation helpers need only an in-memory array in this CLI process, not a browser session.
    $_SESSION = [];
    $pdo = $container->get(PDO::class);
    $users = $container->get(UserRepository::class);
    $actor = 0;
    foreach ($users->all() as $user) {
        if ($user['role'] === 'ADMIN' && (int) $user['active'] === 1) {
            $actor = (int) $user['id'];
            break;
        }
    }
    $service = new DemoSeedService(new DemoSeedRepository($pdo), $users,
        $container->get(PurokRepository::class), $container->get(VaccineScheduleRepository::class),
        $container->get(WhoLmsRepository::class), $container->get(ChildService::class),
        $container->get(MeasurementService::class), $container->get(ImmunizationService::class),
        $container->get(VisitService::class));
    $counts = $service->run($actor);
    fwrite(STDOUT, 'FAKE demo data created: ' . $counts['children'] . ' children, '
        . $counts['measurements'] . ' measurements, ' . $counts['immunizations'] . ' vaccine records, '
        . $counts['visits'] . ' visits; ' . $counts['archived'] . " children archived.\n"
        . "No login accounts or clinical schedules were changed.\n");
} catch (Throwable $error) {
    (new ErrorHandler(dirname(__DIR__) . '/storage/logs/app.log'))->log($error);
    fwrite(STDERR, "Demo seed failed. Requires an empty registry, an active Admin, WHO data and a vaccine schedule.\n"
        . "Check migrations, local configuration and storage/logs/app.log.\n"
        . "If interrupted, completed records remain; rebuild only the disposable demo database before retrying.\n");
    exit(1);
}
