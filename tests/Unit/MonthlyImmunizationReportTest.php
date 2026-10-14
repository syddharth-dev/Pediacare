<?php

declare(strict_types=1);

use Pediacare\Controllers\ReportController;
use Pediacare\Core\{Container, Csrf, Request, Router, Session, View};
use Pediacare\Middleware\{AuthMiddleware, RoleMiddleware};
use Pediacare\Repositories\{LoginAttemptRepository, ReportRepository, UserRepository};
use Pediacare\Services\{AuthService, ReportService};
use Pediacare\Validation\ReportFilterValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonthlyImmunizationReportTest extends TestCase
{
    #[DataProvider('roles')]
    public function test_AC01_AC03_AC08_role_gates_and_aggregate_monthly_output(?string $role, int $status): void
    {
        $previous = $_SESSION ?? [];
        $_SESSION = ['user_id' => 42];
        try {
            $repo = $this->createMock(ReportRepository::class);
            $allowed = $status === 200;
            $filters = ['from' => '2024-02-01', 'to' => '2024-02-29', 'include_archived' => '1', 'month' => '2024-02'];
            $repo->expects($allowed ? self::once() : self::never())->method('monthlyDoses')->with($filters)
                ->willReturn([['vaccine_name' => '<FAKE & Vaccine>', 'male_count' => '2', 'female_count' => '3']]);
            $repo->expects($allowed ? self::once() : self::never())->method('monthlyFic')->with($filters)
                ->willReturn(['male_count' => '1', 'female_count' => '2']);
            $users = $this->createMock(UserRepository::class);
            $users->method('findById')->willReturn($role === null ? null :
                ['id' => 42, 'name' => 'FAKE Staff', 'role' => $role, 'active' => 1]);
            $auth = new AuthService($users, $this->createMock(LoginAttemptRepository::class), new Session());
            $container = new Container();
            $container->register(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($auth));
            $container->register(RoleMiddleware::class, static fn (): RoleMiddleware => new RoleMiddleware($auth));
            $container->register(ReportController::class, static fn (): ReportController => new ReportController(
                new ReportService($repo, new ReportFilterValidator()), $auth, new Csrf($_SESSION), new View(dirname(__DIR__, 2) . '/views')));
            $router = new Router($container, require dirname(__DIR__, 2) . '/src/routes.php');
            $response = $router->dispatch(new Request('GET', '/reports/immunization',
                ['month' => '2024-02', 'include_archived' => '1', 'purok_id' => '123', 'child_id' => '456']));
            self::assertSame($status, $response->status);
            if ($allowed) {
                self::assertSame('no-store', $response->headers['Cache-Control']);
                self::assertStringContainsString('&lt;FAKE &amp; Vaccine&gt;', $response->body);
                self::assertMatchesRegularExpression('/Children completing FIC\s*3\b/', strip_tags($response->body));
                self::assertStringContainsString('<td>5</td>', $response->body);
                self::assertStringNotContainsString('/children/', $response->body);
                self::assertStringNotContainsString('name="purok_id"', $response->body);
                self::assertStringContainsString('for="month"', $response->body);
            } elseif ($role === null) {
                self::assertSame('/login', $response->headers['Location']);
            }
        } finally {
            $_SESSION = $previous;
        }
    }

    public static function roles(): array
    {
        return [[null, 303], ['BHW', 403], ['ADMIN', 200], ['STAFF', 200], ['VIEWER', 200]];
    }

    public function test_AC03_AC13_immunization_csv_contains_matching_dose_and_fic_totals(): void
    {
        $repo = $this->createMock(ReportRepository::class);
        $repo->method('monthlyDoses')->willReturn([['vaccine_name' => 'FAKE Vaccine', 'male_count' => '2', 'female_count' => '3']]);
        $repo->method('monthlyFic')->willReturn(['male_count' => '1', 'female_count' => '2']);
        $result = (new ReportService($repo, new ReportFilterValidator()))->immunization(['month' => '2024-02'], 'VIEWER');
        $csv = \Pediacare\Support\ReportOutput::csv('immunization', $result);
        self::assertStringContainsString('2024-02-01,2024-02-29,0,"FAKE Vaccine",2,3,5', $csv->body);
        self::assertStringContainsString('"Total doses",2,3,5', $csv->body);
        self::assertStringContainsString('"Children completing FIC this month",1,2,3', $csv->body);
        self::assertStringNotContainsString('child_id', $csv->body);
    }

    public function test_AC03_invalid_month_returns_422_and_escaped_form_value_without_results(): void
    {
        $previous = $_SESSION ?? [];
        $_SESSION = ['user_id' => 42];
        try {
            $repo = $this->createMock(ReportRepository::class);
            $repo->expects(self::never())->method('monthlyDoses');
            $repo->expects(self::never())->method('monthlyFic');
            $users = $this->createMock(UserRepository::class);
            $users->method('findById')->willReturn(['id' => 42, 'name' => 'FAKE Viewer', 'role' => 'VIEWER', 'active' => 1]);
            $auth = new AuthService($users, $this->createMock(LoginAttemptRepository::class), new Session());
            $controller = new ReportController(new ReportService($repo, new ReportFilterValidator()),
                $auth, new Csrf($_SESSION), new View(dirname(__DIR__, 2) . '/views'));
            $response = $controller->immunization(new Request('GET', '/reports/immunization', ['month' => '<script>']));
            self::assertSame(422, $response->status);
            self::assertStringContainsString('Enter a valid reporting month.', $response->body);
            self::assertStringContainsString('&lt;script&gt;', $response->body);
            self::assertStringNotContainsString('<table', $response->body);
            self::assertStringNotContainsString('href="/children"', $response->body);
        } finally {
            $_SESSION = $previous;
        }
    }

    #[DataProvider('invalidFilters')]
    public function test_AC03_invalid_month_filters_rejected_before_database_access(array $input): void
    {
        $repo = $this->createMock(ReportRepository::class);
        $repo->expects(self::never())->method('monthlyDoses');
        $repo->expects(self::never())->method('monthlyFic');
        $this->expectException(InvalidArgumentException::class);
        (new ReportService($repo, new ReportFilterValidator()))->immunization($input, 'VIEWER');
    }

    public static function invalidFilters(): array
    {
        return array_map(static fn (array $input): array => [$input], [
            ['month' => ''], ['month' => []], ['month' => '0000-01'], ['month' => '0999-12'],
            ['month' => '2026-00'], ['month' => '2026-13'], ['month' => '2026-1'],
            ['month' => '2026-01-01'], ['month' => "2026-01\0"], ['month' => "2026-01' OR 1=1"],
            ['include_archived' => []], ['include_archived' => '2'],
        ]);
    }

    public function test_AC08_empty_summary_has_zero_totals_and_default_calendar_month(): void
    {
        $repo = $this->createMock(ReportRepository::class);
        $repo->method('monthlyDoses')->willReturn([]);
        $repo->method('monthlyFic')->willReturn(['male_count' => '0', 'female_count' => '0']);
        $result = (new ReportService($repo, new ReportFilterValidator()))->immunization([], 'STAFF');
        self::assertSame(['M' => 0, 'F' => 0, 'total' => 0], $result['totals']);
        self::assertSame($result['totals'], $result['fic']);
        self::assertSame(date('Y-m-01'), $result['filters']['from']);
        self::assertSame(date('Y-m-t'), $result['filters']['to']);
        self::assertSame('0', $result['filters']['include_archived']);
    }
}
