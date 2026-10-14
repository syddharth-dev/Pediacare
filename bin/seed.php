<?php

declare(strict_types=1);

use Pediacare\Core\ErrorHandler;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\AdminSeedService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$arguments = array_slice($argv, 1);

if ($arguments === ['--help']) {
    fwrite(STDOUT, "Usage: php bin/seed.php\nCreates the first Admin from config.local.php.\n");
    exit(0);
}

if ($arguments !== []) {
    fwrite(STDERR, "Usage: php bin/seed.php\n");
    exit(2);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $container = require dirname(__DIR__) . '/src/bootstrap.php';
    $service = new AdminSeedService(new UserRepository($container->get(PDO::class)));
    $created = $service->run($container->config['first_admin'] ?? []);
    fwrite(STDOUT, $created ? "First Admin created.\n" : "Admin already exists; no accounts changed.\n");
} catch (Throwable $error) {
    (new ErrorHandler(dirname(__DIR__) . '/storage/logs/app.log'))->log($error);
    fwrite(STDERR, "Admin seed failed. Check migrations, local configuration, and storage/logs/app.log.\n");
    exit(1);
}
