<?php

declare(strict_types=1);

use Pediacare\Controllers\AuthController;
use Pediacare\Controllers\DefaulterController;
use Pediacare\Core\Container;
use Pediacare\Core\Csrf;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\Router;
use Pediacare\Core\Session;
use Pediacare\Core\View;
use Pediacare\Middleware\AuthMiddleware;
use Pediacare\Middleware\RoleMiddleware;
use Pediacare\Repositories\AuditRepository;
use Pediacare\Repositories\ChildRepository;
use Pediacare\Repositories\ImmunizationRepository;
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Repositories\VaccineScheduleRepository;
use Pediacare\Services\AuthService;
use Pediacare\Services\ImmunizationService;
use Pediacare\Services\PurokService;
use Pediacare\Support\AuditLogger;
use Pediacare\Validation\DefaulterFilterValidator;
use Pediacare\Validation\ImmunizationValidator;
use Pediacare\Validation\PurokValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DefaulterListTest extends TestCase
{
    private ChildRepository $children;
    private VaccineScheduleRepository $schedule;
    private ImmunizationRepository $records;
    private UserRepository $users;
    private ImmunizationService $service;
    private Router $router;
    private array $previousSession;

    protected function setUp(): void
    {
        $this->previousSession = $_SESSION ?? [];
        $_SESSION = ['user_id' => 42];
        $this->children = $this->createMock(ChildRepository::class);
        $this->schedule = $this->createMock(VaccineScheduleRepository::class);
        $this->records = $this->createMock(ImmunizationRepository::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->service = new ImmunizationService($this->createMock(PDO::class), $this->children, $this->schedule,
            $this->records, new ImmunizationValidator(), new AuditLogger($this->createMock(AuditRepository::class)));
        $auth = new AuthService($this->users, $this->createMock(LoginAttemptRepository::class), new Session());
        $csrf = new Csrf($_SESSION);
        $view = new View(dirname(__DIR__, 2) . '/views');
        $puroks = $this->createMock(PurokRepository::class);
        $puroks->method('all')->willReturn([['id' => 7, 'name' => '<FAKE & Purok>']]);
        $container = new Container();
        $container->register(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($auth));
        $container->register(RoleMiddleware::class, static fn (): RoleMiddleware => new RoleMiddleware($auth));
        $container->register(AuthController::class, static fn (): AuthController => new AuthController($auth, $csrf, $view));
        $container->register(DefaulterController::class, fn (): DefaulterController => new DefaulterController($this->service,
            new PurokService($puroks, new PurokValidator()), $auth, $csrf, $view));
        $this->router = new Router($container, require dirname(__DIR__, 2) . '/src/routes.php');
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->previousSession;
    }

    #[DataProvider('exportModes')]
    public function test_AC13_defaulter_export_contains_all_matching_records(string $format): void
    {
        $this->allow('STAFF');
        $this->schedule();
        $rows = array_map(static fn (int $id): array => self::child($id, '2000-01-01'), range(1, 26));
        $this->children->method('immunizationBatch')->willReturnOnConsecutiveCalls($rows, []);
        $this->records->method('forChildren')->willReturn([]);
        $response = $this->dispatch('/immunizations/defaulters', ['page' => '2', 'purok_id' => '7', 'vaccine_id' => '7', 'format' => $format]);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('PC-2099-00001', $response->body);
        self::assertStringContainsString('PC-2099-00026', $response->body);
        if ($format === 'csv') {
            self::assertSame(27, count(explode("\r\n", rtrim($response->body))));
            self::assertStringContainsString('<FAKE & Vaccine> — dose 1', $response->body);
        } else {
            self::assertStringContainsString('Showing 1–26 of 26', $response->body);
            self::assertStringContainsString('purok_id=7&amp;vaccine_id=7&amp;format=csv', $response->body);
        }
    }

    public static function exportModes(): array
    {
        return [['csv'], ['print']];
    }

    private function dispatch(string $path = '/immunizations/defaulters', array $query = []): Response
    {
        return $this->router->dispatch(new Request('GET', $path, $query));
    }

    private function allow(string $role): void
    {
        $this->users->method('findById')->willReturn(['id' => 42, 'name' => 'FAKE Staff', 'role' => $role, 'active' => 1]);
    }

    private function schedule(): void
    {
        $dose = ['id' => 11, 'vaccine_id' => 7, 'dose_number' => 1, 'recommended_age_days' => 42,
            'recommended_age_months' => null, 'grace_days' => 7, 'min_interval_days' => 0,
            'min_interval_months' => null, 'counts_toward_fic' => 1];
        $this->schedule->method('all')->willReturn([['id' => 7, 'name' => '<FAKE & Vaccine>', 'doses' => [$dose]],
            ['id' => 8, 'name' => 'FAKE Other Vaccine', 'doses' => [array_replace($dose,
                ['id' => 12, 'vaccine_id' => 8, 'recommended_age_days' => 0, 'grace_days' => 0])]]]);
    }

    private static function child(int $id, string $birth): array
    {
        return ['id' => $id, 'date_of_birth' => $birth, 'purok_id' => 7, 'purok_name' => '<FAKE & Purok>',
            'full_name' => '<FAKE & Child ' . $id . '>', 'record_no' => 'PC-2099-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT)];
    }

    public function test_AC01_logged_out_list_redirects_without_queries(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->schedule->expects(self::never())->method('all');
        self::assertSame(303, $this->dispatch()->status);
    }

    public function test_AC03_viewer_cannot_read_or_see_navigation_to_individual_defaulters(): void
    {
        $this->allow('VIEWER');
        $this->children->expects(self::never())->method('immunizationBatch');
        $this->records->expects(self::never())->method('forChildren');
        self::assertSame(403, $this->dispatch()->status);
        self::assertStringNotContainsString('/immunizations/defaulters', $this->dispatch('/login')->body);
    }

    #[DataProvider('readers')]
    public function test_AC09_authorized_roles_have_labeled_filters_escaped_values_and_empty_state(string $role): void
    {
        $this->allow($role);
        $this->schedule();
        $response = $this->dispatch();
        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertStringContainsString('No children have overdue doses', $response->body);
        self::assertStringContainsString('&lt;FAKE &amp; Vaccine&gt;', $response->body);
        self::assertStringContainsString('&lt;FAKE &amp; Purok&gt;', $response->body);
        self::assertStringContainsString('/immunizations/defaulters', $this->dispatch('/login')->body);
        $document = new DOMDocument();
        @$document->loadHTML($response->body);
        $xpath = new DOMXPath($document);
        foreach (['purok_id', 'vaccine_id'] as $id) {
            self::assertSame(1, $xpath->query('//label[@for="' . $id . '"]')->length);
            self::assertSame(1, $xpath->query('//select[@id="' . $id . '"]')->length);
        }
    }

    public static function readers(): array
    {
        return [['ADMIN'], ['STAFF'], ['BHW']];
    }

    public function test_AC09_shared_calculator_excludes_given_due_and_upcoming_and_includes_overdue(): void
    {
        $this->schedule();
        $children = [self::child(1, '2026-01-01'), self::child(2, '2026-01-01'), self::child(3, '2026-01-02'), self::child(4, '2026-01-10')];
        $this->children->method('immunizationBatch')->willReturnCallback(static fn (int $after): array => $after === 0 ? $children : []);
        $this->records->expects(self::once())->method('forChildren')->with([1, 2, 3, 4])->willReturn([
            1 => [['schedule_dose_id' => 11, 'date_given' => '2026-02-12']],
            2 => [['schedule_dose_id' => 12, 'date_given' => '2026-01-01']],
            3 => [['schedule_dose_id' => 12, 'date_given' => '2026-01-02']],
            4 => [['schedule_dose_id' => 12, 'date_given' => '2026-01-10']],
        ]);
        $result = $this->service->defaulters([], new DateTimeImmutable('2026-02-20'));
        self::assertSame([1, 2], array_column($result['children'], 'id'));
        self::assertSame(['FAKE Other Vaccine — dose 1'], $result['children'][0]['overdue']);
        self::assertSame(['<FAKE & Vaccine> — dose 1'], $result['children'][1]['overdue']);
        self::assertSame(2, $result['total']);
    }

    public function test_AC09_vaccine_filter_excludes_children_overdue_only_for_another_vaccine(): void
    {
        $this->schedule();
        $this->children->method('immunizationBatch')->willReturnCallback(static fn (int $after, ?int $purok): array => $after === 0 && $purok === 7
            ? [self::child(1, '2026-01-01'), self::child(2, '2026-01-01')] : []);
        $this->records->method('forChildren')->willReturn([1 => [['schedule_dose_id' => 11, 'date_given' => '2026-02-12']]]);
        $result = $this->service->defaulters(['vaccine_id' => '7', 'purok_id' => '7'], new DateTimeImmutable('2026-03-01'));
        self::assertSame([2], array_column($result['children'], 'id'));
        self::assertSame(['<FAKE & Vaccine> — dose 1'], $result['children'][0]['overdue']);
    }

    public function test_AC09_pagination_follows_all_batches_and_preserves_filters_in_links(): void
    {
        $this->allow('BHW');
        $this->schedule();
        $this->children->expects(self::exactly(3))->method('immunizationBatch')->willReturnCallback(static function (int $after): array {
            $ids = $after === 0 ? range(1, 20) : ($after === 20 ? range(21, 51) : []);
            return array_map(static fn (int $id): array => self::child($id, '2026-01-01'), $ids);
        });
        $this->records->expects(self::exactly(2))->method('forChildren')->willReturn([]);
        $response = $this->dispatch(query: ['purok_id' => '7', 'vaccine_id' => '7', 'page' => '2']);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Showing 26–50 of 51', $response->body);
        self::assertStringContainsString('purok_id=7&amp;vaccine_id=7&amp;page=3', $response->body);
        self::assertStringContainsString('&lt;FAKE &amp; Child 26&gt;', $response->body);
        self::assertStringNotContainsString('<FAKE & Child', $response->body);
    }

    #[DataProvider('invalidFilters')]
    public function test_AC09_invalid_filters_never_query_health_records(array $input): void
    {
        $this->allow('STAFF');
        $this->schedule->expects(self::never())->method('all');
        $this->children->expects(self::never())->method('immunizationBatch');
        self::assertSame(422, $this->dispatch(query: $input)->status);
    }

    public static function invalidFilters(): array
    {
        $cases = [];
        foreach (['purok_id', 'vaccine_id', 'page'] as $field) {
            foreach (['0', '-1', '1 OR 1=1', [], "1\0", '4294967296'] as $value) {
                $cases[] = [[$field => $value]];
            }
        }
        return [...$cases, [['page' => '']], [['page' => '2147483648']]];
    }

    public function test_AC09_unknown_vaccine_does_not_scan_children(): void
    {
        $this->schedule();
        $this->children->expects(self::never())->method('immunizationBatch');
        self::assertSame(0, $this->service->defaulters(['vaccine_id' => '999'])['total']);
        self::assertSame(['purok_id' => '', 'vaccine_id' => '', 'page' => '1'], (new DefaulterFilterValidator())->validate([]));
    }
}
