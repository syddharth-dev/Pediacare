<?php

declare(strict_types=1);

use Pediacare\Controllers\{AuthController, ReportController};
use Pediacare\Core\{Container, Csrf, Request, Router, Session, View};
use Pediacare\Middleware\{AuthMiddleware, RoleMiddleware};
use Pediacare\Repositories\{LoginAttemptRepository, ReportRepository, UserRepository};
use Pediacare\Services\{AuthService, ReportService};
use Pediacare\Validation\ReportFilterValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MalnutritionReportTest extends TestCase
{
    private ReportRepository $repo;
    private UserRepository $users;
    private ReportService $service;
    private Router $router;
    private array $previous;

    protected function setUp(): void
    {
        $this->previous = $_SESSION ?? [];
        $_SESSION = ['user_id' => 42];
        $this->repo = $this->createMock(ReportRepository::class);
        $this->service = new ReportService($this->repo, new ReportFilterValidator());
        $this->users = $this->createMock(UserRepository::class);
        $auth = new AuthService($this->users, $this->createMock(LoginAttemptRepository::class), new Session());
        $csrf = new Csrf($_SESSION);
        $view = new View(dirname(__DIR__, 2) . '/views');
        $container = new Container();
        $container->register(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($auth));
        $container->register(RoleMiddleware::class, static fn (): RoleMiddleware => new RoleMiddleware($auth));
        $container->register(ReportController::class, fn (): ReportController => new ReportController($this->service, $auth, $csrf, $view));
        $container->register(AuthController::class, static fn (): AuthController => new AuthController($auth, $csrf, $view));
        $this->router = new Router($container, require dirname(__DIR__, 2) . '/src/routes.php');
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->previous;
    }

    private function allow(string $role): void
    {
        $this->users->method('findById')->willReturn(['id' => 42, 'name' => 'FAKE Staff', 'role' => $role, 'active' => 1]);
    }

    #[DataProvider('roles')]
    public function test_AC01_AC03_roles_gate_individual_list_and_navigation(?string $role, int $status): void
    {
        if ($role !== null) {
            $this->allow($role);
        }
        $allowed = $status === 200;
        $this->repo->expects($allowed ? self::once() : self::never())->method('malnutritionCount')->willReturn(26);
        $this->repo->expects($allowed ? self::once() : self::never())->method('malnutritionPage')
            ->with(['page' => '2', 'include_archived' => '1'], 25, 25)->willReturn([[
                'id' => 7, 'record_no' => 'PC-2090-00007', 'first_name' => '<FAKE>', 'middle_name' => null,
                'last_name' => 'FAMILY', 'suffix' => null, 'sex' => 'M', 'purok_name' => '<FAKE PUROK>',
                'archived_at' => '2090-01-01', 'measured_at' => '2090-01-01', 'wfa_status' => 'Underweight',
                'hfa_status' => 'Tall', 'wfh_status' => null, 'bmi_status' => 'Normal']]);
        $response = $this->router->dispatch(new Request('GET', '/reports/malnutrition', ['page' => '999', 'include_archived' => '1']));
        self::assertSame($status, $response->status);
        $login = $this->router->dispatch(new Request('GET', '/login'))->body;
        if ($allowed) {
            self::assertSame('no-store', $response->headers['Cache-Control']);
            self::assertStringContainsString('&lt;FAKE&gt; FAMILY', $response->body);
            self::assertStringContainsString('&lt;FAKE PUROK&gt;', $response->body);
            self::assertStringContainsString('/children/7?tab=growth', $response->body);
            self::assertStringContainsString('Page 2 of 2', $response->body);
            self::assertStringContainsString('page=1&amp;include_archived=1', $response->body);
            self::assertStringContainsString('Not available', $response->body);
            self::assertStringContainsString('Archived', $response->body);
            self::assertStringContainsString('/reports/malnutrition', $login);
        } else {
            self::assertStringNotContainsString('/reports/malnutrition', $login);
        }
    }

    public static function roles(): array
    {
        return [[null, 303], ['VIEWER', 403], ['ADMIN', 200], ['STAFF', 200], ['BHW', 200]];
    }

    #[DataProvider('exportModes')]
    public function test_AC03_AC13_list_export_requests_all_matching_children(string $format): void
    {
        $this->allow('STAFF');
        $this->repo->method('malnutritionCount')->willReturn(26);
        $children = array_map(static fn (int $id): array => [
            'id' => $id, 'record_no' => 'PC-2090-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT),
            'first_name' => 'FAKE CHILD ' . $id, 'middle_name' => null, 'last_name' => 'FAKE FAMILY', 'suffix' => null,
            'sex' => 'F', 'purok_name' => 'FAKE North', 'archived_at' => null, 'measured_at' => '2090-01-01',
            'wfa_status' => 'Underweight', 'hfa_status' => 'Normal', 'wfh_status' => null, 'bmi_status' => 'Normal',
        ], range(1, 26));
        $this->repo->expects(self::once())->method('malnutritionPage')->with(['page' => '1', 'include_archived' => '1'], 26, 0)->willReturn($children);
        $response = $this->router->dispatch(new Request('GET', '/reports/malnutrition', ['page' => '2', 'include_archived' => '1', 'format' => $format]));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('PC-2090-00001', $response->body);
        self::assertStringContainsString('PC-2090-00026', $response->body);
        if ($format === 'csv') {
            self::assertSame(27, count(explode("\r\n", rtrim($response->body))));
            self::assertStringContainsString('Female,"FAKE North",2090-01-01,Underweight,Normal,"Not available",Normal,No', $response->body);
        } else {
            self::assertStringContainsString('Showing 1–26 of 26', $response->body);
            self::assertStringContainsString('class="report-page report-print"', $response->body);
        }
    }

    public static function exportModes(): array
    {
        return [['csv'], ['print']];
    }

    #[DataProvider('invalidFilters')]
    public function test_AC12_bad_filters_return_422_before_querying(array $input): void
    {
        $this->allow('STAFF');
        $this->repo->expects(self::never())->method('malnutritionCount');
        $this->repo->expects(self::never())->method('malnutritionPage');
        $response = $this->router->dispatch(new Request('GET', '/reports/malnutrition', $input));
        self::assertSame(422, $response->status);
        self::assertStringContainsString(array_key_exists('format', $input)
            ? 'Choose HTML, CSV or print output.' : 'Choose a valid page and Include archived option.', $response->body);
        self::assertStringNotContainsString('<table', $response->body);
    }

    public static function invalidFilters(): array
    {
        return array_map(static fn (array $input): array => [$input], [
            ['page' => '0'], ['page' => '-1'], ['page' => '01'], ['page' => '2147483648'],
            ['page' => '1 OR 1=1'], ['page' => []], ['page' => ''],
            ['include_archived' => '2'], ['include_archived' => []],
            ['format' => []], ['format' => 'csv.exe'], ['format' => "csv\r\nInjected: value"],
        ]);
    }

    public function test_AC12_empty_list_has_guidance_and_no_record_links(): void
    {
        $this->allow('BHW');
        $this->repo->method('malnutritionCount')->willReturn(0);
        $this->repo->expects(self::once())->method('malnutritionPage')->with(['page' => '1', 'include_archived' => '0'], 25, 0)->willReturn([]);
        $response = $this->router->dispatch(new Request('GET', '/reports/malnutrition'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Showing 0–0 of 0', $response->body);
        self::assertStringContainsString('No children have a latest measurement', $response->body);
        self::assertStringNotContainsString('/children/', $response->body);
    }

    public function test_AC03_viewer_service_cannot_bypass_route_permissions(): void
    {
        $this->repo->expects(self::never())->method('malnutritionCount');
        $this->expectException(InvalidArgumentException::class);
        $this->service->malnutrition([], 'VIEWER');
    }
}
