<?php

declare(strict_types=1);

use Pediacare\Controllers\{DefaulterController, ReportController};
use Pediacare\Core\{Container, Csrf, Request, Router, Session, View};
use Pediacare\Middleware\{AuthMiddleware, RoleMiddleware};
use Pediacare\Repositories\{AuditRepository, ChildRepository, ImmunizationRepository, LoginAttemptRepository,
    PurokRepository, ReportRepository, UserRepository, VaccineScheduleRepository};
use Pediacare\Services\{AuthService, ImmunizationService, PurokService, ReportService};
use Pediacare\Support\AuditLogger;
use Pediacare\Validation\{ImmunizationValidator, PurokValidator, ReportFilterValidator};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportExportAccessTest extends TestCase
{
    #[DataProvider('outputs')]
    public function test_AC01_AC03_AC13_every_report_format_preserves_permissions(string $path, string $format, ?string $role, int $status): void
    {
        $previous = $_SESSION ?? [];
        $_SESSION = ['user_id' => 42];
        try {
            $users = $this->createMock(UserRepository::class);
            $users->method('findById')->willReturn($role === null ? null : ['id' => 42, 'name' => 'FAKE Staff', 'role' => $role, 'active' => 1]);
            $auth = new AuthService($users, $this->createMock(LoginAttemptRepository::class), new Session());
            $repo = $this->createMock(ReportRepository::class);
            $schedule = $this->createMock(VaccineScheduleRepository::class);
            $schedule->method('all')->willReturn([]);
            $repo->method('monthlyDoses')->willReturn([]);
            $repo->method('monthlyFic')->willReturn(['male_count' => '0', 'female_count' => '0']);
            $repo->method('malnutritionCount')->willReturn(0);
            $repo->method('malnutritionPage')->willReturn([]);
            if ($status !== 200) {
                foreach (['nutritionalCounts', 'monthlyDoses', 'monthlyFic', 'malnutritionCount', 'malnutritionPage'] as $method) {
                    $repo->expects(self::never())->method($method);
                }
                $schedule->expects(self::never())->method('all');
            } elseif ($role === 'VIEWER' && $path === '/reports/nutrition') {
                $repo->expects(self::once())->method('nutritionalCounts')->with(self::anything(), false)->willReturn([]);
            }
            $puroks = $this->createMock(PurokRepository::class);
            $puroks->method('all')->willReturn([]);
            $immunizations = new ImmunizationService($this->createMock(PDO::class), $this->createMock(ChildRepository::class),
                $schedule, $this->createMock(ImmunizationRepository::class), new ImmunizationValidator(),
                new AuditLogger($this->createMock(AuditRepository::class)));
            $view = new View(dirname(__DIR__, 2) . '/views');
            $csrf = new Csrf($_SESSION);
            $container = new Container();
            $container->register(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($auth));
            $container->register(RoleMiddleware::class, static fn (): RoleMiddleware => new RoleMiddleware($auth));
            $container->register(ReportController::class, static fn (): ReportController => new ReportController(
                new ReportService($repo, new ReportFilterValidator()), $auth, $csrf, $view));
            $container->register(DefaulterController::class, static fn (): DefaulterController => new DefaulterController(
                $immunizations, new PurokService($puroks, new PurokValidator()), $auth, $csrf, $view));
            $router = new Router($container, require dirname(__DIR__, 2) . '/src/routes.php');
            $response = $router->dispatch(new Request('GET', $path, ['format' => $format, 'purok_id' => '', 'child_id' => '42']));
            self::assertSame($status, $response->status);
            if ($status === 200) {
                self::assertSame('no-store', $response->headers['Cache-Control']);
                self::assertSame($format === 'csv' ? 'text/csv; charset=UTF-8' : 'text/html; charset=UTF-8', $response->headers['Content-Type']);
                if ($format === 'print') {
                    self::assertStringContainsString('report-page report-print', $response->body);
                    self::assertStringContainsString('data-print-report', $response->body);
                }
                if ($role === 'VIEWER') {
                    self::assertStringNotContainsString('/children/', $response->body);
                    self::assertStringNotContainsString('Record number', $response->body);
                }
            }
        } finally {
            $_SESSION = $previous;
        }
    }

    public static function outputs(): array
    {
        $cases = [];
        foreach (['/reports/nutrition', '/reports/immunization', '/reports/malnutrition', '/immunizations/defaulters'] as $path) {
            foreach (['csv', 'print'] as $format) {
                foreach ([null, 'ADMIN', 'STAFF', 'BHW', 'VIEWER'] as $role) {
                    $aggregate = in_array($path, ['/reports/nutrition', '/reports/immunization'], true);
                    $status = $role === null ? 303 : (($aggregate && $role === 'BHW') || (!$aggregate && $role === 'VIEWER') ? 403 : 200);
                    $cases[] = [$path, $format, $role, $status];
                }
            }
        }

        return $cases;
    }
}
