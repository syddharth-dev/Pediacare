<?php

declare(strict_types=1);

use Pediacare\Controllers\AuthController;
use Pediacare\Controllers\ReportController;
use Pediacare\Core\Container;
use Pediacare\Core\Csrf;
use Pediacare\Core\Request;
use Pediacare\Core\Router;
use Pediacare\Core\Session;
use Pediacare\Core\View;
use Pediacare\Middleware\AuthMiddleware;
use Pediacare\Middleware\RoleMiddleware;
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\ReportRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\AuthService;
use Pediacare\Services\ReportService;
use Pediacare\Validation\ReportFilterValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NutritionalReportTest extends TestCase
{
    private ReportRepository $repository;
    private UserRepository $users;
    private Router $router;
    private ReportService $service;
    private array $previousSession;

    protected function setUp(): void
    {
        $this->previousSession = $_SESSION ?? [];
        $_SESSION = ['user_id' => 42];
        $this->repository = $this->createMock(ReportRepository::class);
        $this->service = new ReportService($this->repository, new ReportFilterValidator());
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
        $_SESSION = $this->previousSession;
    }

    private function allow(string $role): void
    {
        $this->users->method('findById')->willReturn(['id' => 42, 'name' => 'FAKE Report Staff', 'role' => $role, 'active' => 1]);
    }

    public function test_AC01_logged_out_summary_redirects_before_querying(): void
    {
        $this->repository->expects(self::never())->method('nutritionalCounts');
        $response = $this->router->dispatch(new Request('GET', '/reports/nutrition'));
        self::assertSame('/login', $response->headers['Location']);
    }

    public function test_AC03_bhw_is_denied_report_and_has_no_report_navigation(): void
    {
        $this->allow('BHW');
        $this->repository->expects(self::never())->method('nutritionalCounts');
        self::assertSame(403, $this->router->dispatch(new Request('GET', '/reports/nutrition'))->status);
        self::assertStringNotContainsString('/reports/nutrition', $this->router->dispatch(new Request('GET', '/login'))->body);
    }

    #[DataProvider('readers')]
    public function test_AC03_AC10_authorized_roles_get_aggregate_tables_and_role_scoped_queries(string $role): void
    {
        $this->allow($role);
        $row = ['sex' => 'F', 'wfa_status' => 'Normal', 'hfa_status' => 'Stunted',
            'wfh_status' => null, 'bmi_status' => 'Normal', 'child_count' => '2'];
        if ($role !== 'VIEWER') {
            $row += ['purok_id' => 7, 'purok_name' => '<FAKE & Purok>'];
        }
        $this->repository->expects(self::once())->method('nutritionalCounts')->with(
            ['from' => '2026-01-01', 'to' => '2026-01-31', 'include_archived' => '1'], $role !== 'VIEWER')->willReturn([$row]);
        $response = $this->router->dispatch(new Request('GET', '/reports/nutrition', [
            'from' => '2026-01-01', 'to' => '2026-01-31', 'include_archived' => '1', 'purok_id' => '7', 'byPurok' => '1']));
        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertMatchesRegularExpression('/Children counted\s*2\b/', strip_tags($response->body));
        self::assertStringContainsString('Not available', $response->body);
        self::assertStringNotContainsString('/children/', $response->body);
        self::assertStringNotContainsString('<FAKE & Purok>', $response->body);
        if ($role === 'VIEWER') {
            self::assertStringNotContainsString('FAKE &amp; Purok', $response->body);
            self::assertStringNotContainsString('name="purok_id"', $response->body);
        } else {
            self::assertStringContainsString('&lt;FAKE &amp; Purok&gt;', $response->body);
        }
        $document = new DOMDocument();
        @$document->loadHTML($response->body);
        $xpath = new DOMXPath($document);
        self::assertSame($role === 'VIEWER' ? 4 : 8, $xpath->query('//table')->length);
        foreach ($xpath->query('//form//input[not(@type="hidden")]') as $input) {
            self::assertSame(1, $xpath->query('//label[@for="' . $input->getAttribute('id') . '"]')->length);
        }
        self::assertStringContainsString('/reports/nutrition', $this->router->dispatch(new Request('GET', '/login'))->body);
    }

    public static function readers(): array
    {
        return [['ADMIN'], ['STAFF'], ['VIEWER']];
    }

    public function test_AC13_invalid_csv_filter_returns_html_error_without_attachment(): void
    {
        $this->allow('STAFF');
        $this->repository->expects(self::never())->method('nutritionalCounts');
        $response = $this->router->dispatch(new Request('GET', '/reports/nutrition', ['format' => 'csv', 'from' => 'invalid']));
        self::assertSame(422, $response->status);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
        self::assertArrayNotHasKey('Content-Disposition', $response->headers);
        self::assertStringNotContainsString('Download CSV', $response->body);
    }

    public function test_AC13_invalid_print_filter_keeps_editable_filter_form_visible(): void
    {
        $this->allow('STAFF');
        $this->repository->expects(self::never())->method('nutritionalCounts');
        $response = $this->router->dispatch(new Request('GET', '/reports/nutrition', ['format' => 'print', 'from' => 'invalid']));
        self::assertSame(422, $response->status);
        self::assertStringNotContainsString('report-page report-print', $response->body);
        self::assertStringContainsString('name="from"', $response->body);
    }

    #[DataProvider('readers')]
    public function test_AC03_AC13_nutritional_csv_and_print_match_screen_counts_and_scope(string $role): void
    {
        $this->allow($role);
        $row = ['sex' => 'F', 'wfa_status' => 'Underweight', 'hfa_status' => 'Normal',
            'wfh_status' => null, 'bmi_status' => 'Normal', 'child_count' => '3'];
        if ($role !== 'VIEWER') {
            $row += ['purok_id' => 7, 'purok_name' => 'FAKE North'];
        }
        $this->repository->expects(self::exactly(3))->method('nutritionalCounts')->with(
            ['from' => '2026-01-01', 'to' => '2026-01-31', 'include_archived' => '1'], $role !== 'VIEWER')->willReturn([$row]);
        $filters = ['from' => '2026-01-01', 'to' => '2026-01-31', 'include_archived' => '1', 'purok_id' => '7'];
        $html = $this->router->dispatch(new Request('GET', '/reports/nutrition', $filters));
        self::assertMatchesRegularExpression('/Children counted\s*3\b/', strip_tags($html->body));
        self::assertStringContainsString('format=csv', $html->body);
        $csv = $this->router->dispatch(new Request('GET', '/reports/nutrition', $filters + ['format' => 'csv']));
        self::assertSame('text/csv; charset=UTF-8', $csv->headers['Content-Type']);
        self::assertStringContainsString('Underweight,0,3,3', $csv->body);
        self::assertStringContainsString('"Not available",0,3,3', $csv->body);
        self::assertStringNotContainsString('PC-', $csv->body);
        if ($role === 'VIEWER') {
            self::assertStringNotContainsString('FAKE North', $csv->body);
        } else {
            self::assertStringContainsString('FAKE North', $csv->body);
        }
        $print = $this->router->dispatch(new Request('GET', '/reports/nutrition', $filters + ['format' => 'print']));
        self::assertSame(200, $print->status);
        self::assertStringContainsString('class="report-page report-print"', $print->body);
        self::assertStringContainsString('data-print-report', $print->body);
        self::assertMatchesRegularExpression('/Children counted\s*3\b/', strip_tags($print->body));
        self::assertStringNotContainsString('/children/', $print->body);
    }

    public function test_AC10_saved_categories_total_independently_for_each_sex_and_purok(): void
    {
        $base = ['wfa_status' => 'Normal', 'hfa_status' => 'Normal', 'wfh_status' => null, 'bmi_status' => 'Overweight'];
        $this->repository->method('nutritionalCounts')->willReturn([
            $base + ['sex' => 'M', 'child_count' => '3', 'purok_id' => 7, 'purok_name' => 'FAKE North'],
            array_replace($base, ['wfa_status' => 'Underweight']) + ['sex' => 'F', 'child_count' => '2', 'purok_id' => 8, 'purok_name' => 'FAKE South']]);
        $result = $this->service->nutritional([], 'STAFF');
        self::assertCount(3, $result['groups']);
        self::assertSame(['M' => 3, 'F' => 2, 'total' => 5], $result['groups'][0]['totals']);
        self::assertSame(2, $result['groups'][0]['indicators']['wfa']['Underweight']['F']);
        self::assertSame(5, $result['groups'][0]['indicators']['wfh']['Not available']['total']);
        self::assertSame(3, $result['groups'][1]['totals']['total']);
        self::assertSame(2, $result['groups'][2]['totals']['total']);
        foreach ($result['groups'] as $group) {
            foreach ($group['indicators'] as $categories) {
                self::assertSame($group['totals']['total'], array_sum(array_column($categories, 'total')));
            }
        }
    }

    public function test_AC10_empty_period_shows_zero_counts_and_guidance(): void
    {
        $this->allow('VIEWER');
        $response = $this->router->dispatch(new Request('GET', '/reports/nutrition'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('No children have an eligible measurement', $response->body);
        self::assertMatchesRegularExpression('/Children counted\s*0\b/', strip_tags($response->body));
    }

    #[DataProvider('invalidFilters')]
    public function test_AC10_invalid_report_period_never_queries(array $input): void
    {
        $this->allow('STAFF');
        $this->repository->expects(self::never())->method('nutritionalCounts');
        $response = $this->router->dispatch(new Request('GET', '/reports/nutrition', $input));
        self::assertSame(422, $response->status);
        self::assertStringContainsString('id="child-error"', $response->body);
        self::assertStringNotContainsString('<table', $response->body);
    }

    public static function invalidFilters(): array
    {
        $cases = [];
        foreach (['from', 'to'] as $field) {
            foreach (['', '2026-02-30', '2026-1-1', '0000-01-01', "2026-01-01\0", [], '1 OR 1=1'] as $value) {
                $cases[] = [[$field => $value]];
            }
        }
        return [...$cases, [['from' => '2026-02-01', 'to' => '2026-01-01']],
            [['include_archived' => '2']], [['include_archived' => []]]];
    }
}
