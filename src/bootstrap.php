<?php

declare(strict_types=1);

use Pediacare\Core\Container;
use Pediacare\Core\Csrf;
use Pediacare\Core\Database;
use Pediacare\Core\ErrorHandler;
use Pediacare\Core\Flash;
use Pediacare\Core\Session;
use Pediacare\Core\View;
use Pediacare\Middleware\CsrfMiddleware;
use Pediacare\Middleware\AuthMiddleware;
use Pediacare\Middleware\RoleMiddleware;
use Pediacare\Controllers\AuthController;
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\AuthService;
use Pediacare\Services\UserService;
use Pediacare\Validation\UserValidator;
use Pediacare\Controllers\Admin\UserController;
use Pediacare\Repositories\AuditRepository;
use Pediacare\Support\AuditLogger;
use Pediacare\Controllers\Admin\AuditController;
use Pediacare\Services\AuditService;
use Pediacare\Validation\AuditFilterValidator;
use Pediacare\Controllers\Admin\PurokController;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Services\PurokService;
use Pediacare\Validation\PurokValidator;
use Pediacare\Repositories\RecordCounterRepository;
use Pediacare\Support\RecordNumberGenerator;
use Pediacare\Controllers\ChildController;
use Pediacare\Repositories\ChildRepository;
use Pediacare\Services\ChildService;
use Pediacare\Support\DuplicateConfirmation;
use Pediacare\Validation\ChildValidator;
use Pediacare\Validation\ChildFilterValidator;
use Pediacare\Controllers\MeasurementController;
use Pediacare\Repositories\MeasurementRepository;
use Pediacare\Repositories\WhoLmsRepository;
use Pediacare\Services\MeasurementService;
use Pediacare\Support\MeasurementConfirmation;
use Pediacare\Validation\MeasurementValidator;

require_once dirname(__DIR__) . '/vendor/autoload.php';

return (static function (): Container {
    (new ErrorHandler(dirname(__DIR__) . '/storage/logs/app.log'))->register();

    $configPath = dirname(__DIR__) . '/config/config.local.php';

    if (!is_file($configPath)) {
        throw new RuntimeException('Local configuration is missing. Follow the setup steps in README.md.');
    }

    $config = require $configPath;

    if (!is_array($config)) {
        throw new RuntimeException('Local configuration must return an array.');
    }

    date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Manila');

    $container = new Container($config);
    $container->register(Session::class, static fn (Container $container): Session => new Session());
    $container->register(Csrf::class, static function (Container $container): Csrf {
        return new Csrf($_SESSION);
    });
    $container->register(CsrfMiddleware::class, static fn (Container $container): CsrfMiddleware => new CsrfMiddleware(
        $container->get(Csrf::class)
    ));
    $container->register(PDO::class, static fn (Container $container): PDO => Database::connect(
        $container->config['database']
    ));
    $container->register(View::class, static fn (Container $container): View => new View(dirname(__DIR__) . '/views'));
    $container->register(UserRepository::class, static fn (Container $container): UserRepository => new UserRepository(
        $container->get(PDO::class)
    ));
    $container->register(LoginAttemptRepository::class, static fn (Container $container): LoginAttemptRepository => new LoginAttemptRepository(
        $container->get(PDO::class)
    ));
    $container->register(AuthService::class, static fn (Container $container): AuthService => new AuthService(
        $container->get(UserRepository::class), $container->get(LoginAttemptRepository::class), $container->get(Session::class)
    ));
    $container->register(AuthController::class, static fn (Container $container): AuthController => new AuthController(
        $container->get(AuthService::class), $container->get(Csrf::class), $container->get(View::class)
    ));
    $container->register(AuthMiddleware::class, static fn (Container $container): AuthMiddleware => new AuthMiddleware(
        $container->get(AuthService::class)
    ));
    $container->register(RoleMiddleware::class, static fn (Container $container): RoleMiddleware => new RoleMiddleware(
        $container->get(AuthService::class)
    ));
    $container->register(Flash::class, static function (Container $container): Flash {
        return new Flash($_SESSION);
    });
    $container->register(UserService::class, static fn (Container $container): UserService => new UserService(
        $container->get(UserRepository::class), new UserValidator()
    ));
    $container->register(AuditRepository::class, static fn (Container $container): AuditRepository => new AuditRepository(
        $container->get(PDO::class)
    ));
    $container->register(AuditLogger::class, static fn (Container $container): AuditLogger => new AuditLogger(
        $container->get(AuditRepository::class), $_SERVER['REMOTE_ADDR'] ?? null
    ));
    $container->register(UserController::class, static fn (Container $container): UserController => new UserController(
        $container->get(UserService::class), $container->get(AuthService::class), $container->get(Csrf::class),
        $container->get(Flash::class), $container->get(View::class)
    ));

    $container->register(AuditService::class, static fn (Container $container): AuditService => new AuditService(
        $container->get(AuditRepository::class)
    ));
    $container->register(AuditController::class, static fn (Container $container): AuditController => new AuditController(
        $container->get(AuditService::class), $container->get(UserService::class),
        $container->get(AuthService::class), new AuditFilterValidator(), $container->get(View::class)
    ));

    $container->register(PurokRepository::class, static fn (Container $container): PurokRepository => new PurokRepository(
        $container->get(PDO::class)
    ));
    $container->register(PurokService::class, static fn (Container $container): PurokService => new PurokService(
        $container->get(PurokRepository::class), new PurokValidator()
    ));
    $container->register(PurokController::class, static fn (Container $container): PurokController => new PurokController(
        $container->get(PurokService::class), $container->get(AuthService::class), $container->get(Csrf::class),
        $container->get(Flash::class), $container->get(View::class)
    ));

    $container->register(RecordCounterRepository::class, static fn (Container $container): RecordCounterRepository => new RecordCounterRepository(
        $container->get(PDO::class)
    ));
    $container->register(RecordNumberGenerator::class, static fn (Container $container): RecordNumberGenerator => new RecordNumberGenerator(
        $container->get(RecordCounterRepository::class)
    ));

    $container->register(ChildRepository::class, static fn (Container $container): ChildRepository => new ChildRepository(
        $container->get(PDO::class)
    ));
    $container->register(ChildService::class, static fn (Container $container): ChildService => new ChildService(
        $container->get(PDO::class), $container->get(ChildRepository::class), $container->get(PurokRepository::class),
        new ChildValidator(), $container->get(RecordNumberGenerator::class), $container->get(AuditLogger::class)
    ));
    $container->register(DuplicateConfirmation::class, static function (Container $container): DuplicateConfirmation {
        return new DuplicateConfirmation($_SESSION);
    });
    $container->register(ChildController::class, static fn (Container $container): ChildController => new ChildController(
        $container->get(ChildService::class), $container->get(PurokService::class), $container->get(AuthService::class),
        $container->get(Csrf::class), $container->get(Flash::class), $container->get(DuplicateConfirmation::class),
            $container->get(View::class), new ChildFilterValidator(), $container->get(\Pediacare\Services\GrowthHistoryService::class),
            $container->get(\Pediacare\Services\ImmunizationService::class), $container->get(\Pediacare\Services\HealthTimelineService::class)
    ));

    $container->register(MeasurementRepository::class, static fn (Container $container): MeasurementRepository => new MeasurementRepository($container->get(PDO::class)));
    $container->register(WhoLmsRepository::class, static fn (Container $container): WhoLmsRepository => new WhoLmsRepository($container->get(PDO::class)));
    $container->register(\Pediacare\Services\GrowthHistoryService::class, static fn (Container $container): \Pediacare\Services\GrowthHistoryService => new \Pediacare\Services\GrowthHistoryService(
        $container->get(MeasurementRepository::class), $container->get(WhoLmsRepository::class)
    ));
    $container->register(MeasurementConfirmation::class, static function (): MeasurementConfirmation {
        return new MeasurementConfirmation($_SESSION);
    });
    $container->register(MeasurementService::class, static fn (Container $container): MeasurementService => new MeasurementService(
        $container->get(PDO::class), $container->get(ChildRepository::class), $container->get(MeasurementRepository::class),
        $container->get(WhoLmsRepository::class), new MeasurementValidator(), $container->get(AuditLogger::class),
        $container->get(MeasurementConfirmation::class)
    ));
    $container->register(MeasurementController::class, static fn (Container $container): MeasurementController => new MeasurementController(
        $container->get(MeasurementService::class), $container->get(ChildService::class), $container->get(AuthService::class),
        $container->get(Csrf::class), $container->get(Flash::class), $container->get(View::class)
    ));

    $container->register(\Pediacare\Repositories\VaccineScheduleRepository::class, static fn (Container $container): \Pediacare\Repositories\VaccineScheduleRepository => new \Pediacare\Repositories\VaccineScheduleRepository($container->get(PDO::class)));
    $container->register(\Pediacare\Services\VaccineScheduleService::class, static fn (Container $container): \Pediacare\Services\VaccineScheduleService => new \Pediacare\Services\VaccineScheduleService(
        $container->get(PDO::class), $container->get(\Pediacare\Repositories\VaccineScheduleRepository::class),
        new \Pediacare\Validation\VaccineScheduleValidator(), $container->get(AuditLogger::class)
    ));
    $container->register(\Pediacare\Controllers\Admin\ScheduleController::class, static fn (Container $container): \Pediacare\Controllers\Admin\ScheduleController => new \Pediacare\Controllers\Admin\ScheduleController(
        $container->get(\Pediacare\Services\VaccineScheduleService::class), $container->get(AuthService::class),
        $container->get(Csrf::class), $container->get(Flash::class), $container->get(View::class)
    ));

    $container->register(\Pediacare\Repositories\ImmunizationRepository::class, static fn (Container $container): \Pediacare\Repositories\ImmunizationRepository => new \Pediacare\Repositories\ImmunizationRepository($container->get(PDO::class)));
    $container->register(\Pediacare\Services\ImmunizationService::class, static fn (Container $container): \Pediacare\Services\ImmunizationService => new \Pediacare\Services\ImmunizationService(
        $container->get(PDO::class), $container->get(ChildRepository::class), $container->get(\Pediacare\Repositories\VaccineScheduleRepository::class),
        $container->get(\Pediacare\Repositories\ImmunizationRepository::class), new \Pediacare\Validation\ImmunizationValidator(), $container->get(AuditLogger::class)
    ));
    $container->register(\Pediacare\Controllers\ImmunizationController::class, static fn (Container $container): \Pediacare\Controllers\ImmunizationController => new \Pediacare\Controllers\ImmunizationController(
        $container->get(\Pediacare\Services\ImmunizationService::class), $container->get(ChildService::class),
        $container->get(AuthService::class), $container->get(Csrf::class), $container->get(Flash::class), $container->get(View::class)
    ));

    $container->register(\Pediacare\Controllers\DefaulterController::class, static fn (Container $container): \Pediacare\Controllers\DefaulterController => new \Pediacare\Controllers\DefaulterController(
        $container->get(\Pediacare\Services\ImmunizationService::class), $container->get(PurokService::class),
        $container->get(AuthService::class), $container->get(Csrf::class), $container->get(View::class)
    ));

    $container->register(\Pediacare\Repositories\VisitRepository::class, static fn (Container $container): \Pediacare\Repositories\VisitRepository => new \Pediacare\Repositories\VisitRepository($container->get(PDO::class)));
    $container->register(\Pediacare\Repositories\SupplementRepository::class, static fn (Container $container): \Pediacare\Repositories\SupplementRepository => new \Pediacare\Repositories\SupplementRepository($container->get(PDO::class)));
    $container->register(\Pediacare\Services\VisitService::class, static fn (Container $container): \Pediacare\Services\VisitService => new \Pediacare\Services\VisitService(
        $container->get(PDO::class), $container->get(ChildRepository::class), $container->get(\Pediacare\Repositories\VisitRepository::class),
        $container->get(\Pediacare\Repositories\SupplementRepository::class), new \Pediacare\Validation\VisitValidator(), $container->get(AuditLogger::class)
    ));
    $container->register(\Pediacare\Controllers\VisitController::class, static fn (Container $container): \Pediacare\Controllers\VisitController => new \Pediacare\Controllers\VisitController(
        $container->get(\Pediacare\Services\VisitService::class), $container->get(ChildService::class),
        $container->get(AuthService::class), $container->get(Csrf::class), $container->get(Flash::class), $container->get(View::class)
    ));

    $container->register(\Pediacare\Services\HealthTimelineService::class, static fn (Container $container): \Pediacare\Services\HealthTimelineService => new \Pediacare\Services\HealthTimelineService(
        $container->get(MeasurementRepository::class), $container->get(\Pediacare\Repositories\ImmunizationRepository::class),
        $container->get(\Pediacare\Repositories\VisitRepository::class), $container->get(\Pediacare\Repositories\SupplementRepository::class)
    ));

    $container->register(\Pediacare\Repositories\ReportRepository::class, static fn (Container $container): \Pediacare\Repositories\ReportRepository => new \Pediacare\Repositories\ReportRepository($container->get(PDO::class)));
    $container->register(\Pediacare\Services\ReportService::class, static fn (Container $container): \Pediacare\Services\ReportService => new \Pediacare\Services\ReportService(
        $container->get(\Pediacare\Repositories\ReportRepository::class), new \Pediacare\Validation\ReportFilterValidator()
    ));
    $container->register(\Pediacare\Controllers\ReportController::class, static fn (Container $container): \Pediacare\Controllers\ReportController => new \Pediacare\Controllers\ReportController(
        $container->get(\Pediacare\Services\ReportService::class), $container->get(AuthService::class), $container->get(Csrf::class), $container->get(View::class)
    ));

    return $container;
})();
