<?php

declare(strict_types=1);

use Pediacare\Controllers\ChildController;
use Pediacare\Controllers\ImmunizationController;
use Pediacare\Core\Container;
use Pediacare\Core\Csrf;
use Pediacare\Core\Flash;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\Router;
use Pediacare\Core\Session;
use Pediacare\Core\View;
use Pediacare\Middleware\AuthMiddleware;
use Pediacare\Middleware\CsrfMiddleware;
use Pediacare\Middleware\RoleMiddleware;
use Pediacare\Repositories\AuditRepository;
use Pediacare\Repositories\ChildRepository;
use Pediacare\Repositories\ImmunizationRepository;
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Repositories\RecordCounterRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Repositories\VaccineScheduleRepository;
use Pediacare\Services\AuthService;
use Pediacare\Services\ChildService;
use Pediacare\Services\ImmunizationService;
use Pediacare\Services\PurokService;
use Pediacare\Support\AuditLogger;
use Pediacare\Support\DuplicateConfirmation;
use Pediacare\Support\RecordNumberGenerator;
use Pediacare\Validation\ChildFilterValidator;
use Pediacare\Validation\ChildValidator;
use Pediacare\Validation\ImmunizationValidator;
use Pediacare\Validation\PurokValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImmunizationWorkflowTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $users;
    private ChildRepository $children;
    private VaccineScheduleRepository $schedule;
    private ImmunizationRepository $records;
    private AuditRepository $audit;
    private ImmunizationService $service;
    private Csrf $csrf;
    private Router $router;
    private bool $active = false;
    private array $previousSession;
    private array $child;
    private array $vaccines;

    protected function setUp(): void
    {
        $this->previousSession = $_SESSION ?? [];
        $_SESSION = ['user_id' => 42];
        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('inTransaction')->willReturnCallback(fn (): bool => $this->active);
        $this->pdo->method('beginTransaction')->willReturnCallback(function (): bool { $this->active = true; return true; });
        $this->pdo->method('commit')->willReturnCallback(function (): bool { $this->active = false; return true; });
        $this->pdo->method('rollBack')->willReturnCallback(function (): bool { $this->active = false; return true; });
        $this->users = $this->createMock(UserRepository::class);
        $this->children = $this->createMock(ChildRepository::class);
        $this->schedule = $this->createMock(VaccineScheduleRepository::class);
        $this->records = $this->createMock(ImmunizationRepository::class);
        $this->audit = $this->createMock(AuditRepository::class);
        $this->child = ['id' => 9, 'date_of_birth' => date('Y-m-d', strtotime('-100 days')), 'archived_at' => null,
            'full_name' => '<FAKE & Child>', 'record_no' => 'PC-2099-00009', 'sex' => 'M'];
        $dose = ['id' => 11, 'vaccine_id' => 7, 'dose_number' => 1, 'recommended_age_days' => 0,
            'recommended_age_months' => null, 'grace_days' => 0, 'min_interval_days' => 0,
            'min_interval_months' => null, 'counts_toward_fic' => 1, 'sort_order' => 1];
        $this->vaccines = [['id' => 7, 'name' => '<FAKE & Vaccine>', 'code' => 'FAKE', 'active' => 1, 'doses' => [$dose]]];
        $this->children->method('findById')->willReturnCallback(fn (int $id): ?array => $id === 9 ? $this->child : null);
        $this->children->method('lockById')->willReturnCallback(fn (int $id): ?array => $id === 9 ? $this->child : null);
        $this->schedule->method('all')->willReturnCallback(fn (): array => $this->vaccines);
        $this->schedule->method('dose')->willReturnCallback(fn (): ?array => $this->vaccines[0]['doses'][0] ?? null);
        $this->schedule->method('vaccine')->willReturnCallback(fn (): array => $this->vaccines[0]);
        $this->service = new ImmunizationService($this->pdo, $this->children, $this->schedule, $this->records,
            new ImmunizationValidator(), new AuditLogger($this->audit));
        $auth = new AuthService($this->users, $this->createMock(LoginAttemptRepository::class), new Session());
        $this->csrf = new Csrf($_SESSION);
        $flash = new Flash($_SESSION);
        $view = new View(dirname(__DIR__, 2) . '/views');
        $puroks = $this->createMock(PurokRepository::class);
        $childService = new ChildService($this->pdo, $this->children, $puroks, new ChildValidator(),
            new RecordNumberGenerator($this->createMock(RecordCounterRepository::class)), new AuditLogger($this->audit));
        $container = new Container();
        $container->register(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($auth));
        $container->register(RoleMiddleware::class, static fn (): RoleMiddleware => new RoleMiddleware($auth));
        $container->register(ImmunizationController::class, fn (): ImmunizationController => new ImmunizationController(
            $this->service, $childService, $auth, $this->csrf, $flash, $view
        ));
        $container->register(ChildController::class, fn (): ChildController => new ChildController($childService,
            new PurokService($puroks, new PurokValidator()), $auth, $this->csrf, $flash, new DuplicateConfirmation($_SESSION),
            $view, new ChildFilterValidator(), new \Pediacare\Services\GrowthHistoryService(
                $this->createMock(\Pediacare\Repositories\MeasurementRepository::class),
                $this->createMock(\Pediacare\Repositories\WhoLmsRepository::class)
            ), $this->service, new \Pediacare\Services\HealthTimelineService(
                $this->createMock(\Pediacare\Repositories\MeasurementRepository::class), $this->records,
                $this->createMock(\Pediacare\Repositories\VisitRepository::class),
                $this->createMock(\Pediacare\Repositories\SupplementRepository::class))));
        $this->router = new Router($container, require dirname(__DIR__, 2) . '/src/routes.php');
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->previousSession;
    }

    private function allow(string $role = 'STAFF'): void
    {
        $this->users->method('findById')->willReturn(['id' => 42, 'name' => '<FAKE & Staff>', 'role' => $role, 'active' => 1]);
    }

    private function input(): array
    {
        return ['schedule_dose_id' => '11', 'date_given' => date('Y-m-d'), 'lot_number' => '', 'site' => '',
            'given_elsewhere' => '1', 'place_given' => '<FAKE Hospital>', '_csrf_token' => $this->csrf->token()];
    }

    private function dispatch(string $method, string $path, array $post = [], array $query = []): Response
    {
        return (new CsrfMiddleware($this->csrf))->handle(new Request($method, $path, $query, $post),
            fn (Request $request): Response => $this->router->dispatch($request));
    }

    public function test_AC01_logged_out_routes_redirect_without_loading_child_data(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->children->expects(self::never())->method('findById');
        $this->children->expects(self::never())->method('lockById');
        foreach ([['GET', '/children/9/immunizations/new'], ['POST', '/children/9/immunizations']] as [$method, $path]) {
            self::assertSame(303, $this->dispatch($method, $path, $this->input())->status);
        }
    }

    #[DataProvider('restrictedRoles')]
    public function test_AC03_bhw_and_viewer_cannot_record_vaccines(string $role): void
    {
        $this->allow($role);
        $this->pdo->expects(self::never())->method('beginTransaction');
        self::assertSame(403, $this->dispatch('GET', '/children/9/immunizations/new')->status);
        self::assertSame(403, $this->dispatch('POST', '/children/9/immunizations', $this->input())->status);
        $profile = $this->dispatch('GET', '/children/9', [], ['tab' => 'immunization']);
        self::assertSame($role === 'VIEWER' ? 403 : 200, $profile->status);
        self::assertStringNotContainsString('/immunizations/new', $profile->body);
    }

    public static function restrictedRoles(): array
    {
        return [['BHW'], ['VIEWER']];
    }

    public function test_AC11_post_requires_csrf_before_writing(): void
    {
        $this->allow();
        $this->pdo->expects(self::never())->method('beginTransaction');
        self::assertSame(403, $this->dispatch('POST', '/children/9/immunizations', array_replace($this->input(), ['_csrf_token' => 'INVALID']))->status);
    }

    public function test_AC11_elsewhere_record_is_attributed_to_authenticated_staff_and_audited_inside_transaction(): void
    {
        $this->allow();
        $expected = ['schedule_dose_id' => 11, 'date_given' => date('Y-m-d'), 'given_elsewhere' => 1,
            'lot_number' => null, 'site' => null, 'place_given' => '<FAKE Hospital>', 'child_id' => 9, 'given_by' => 42];
        $this->records->expects(self::once())->method('insert')->with($expected)->willReturn(15);
        $this->audit->expects(self::once())->method('insert')->willReturnCallback(function (int $actor, string $action, string $entity, int $id, string $changes) use ($expected): void {
            self::assertTrue($this->active);
            self::assertSame([42, 'CREATE', 'immunizations', 15], [$actor, $action, $entity, $id]);
            self::assertSame($expected, json_decode($changes, true, 512, JSON_THROW_ON_ERROR)['after']);
        });
        $response = $this->dispatch('POST', '/children/9/immunizations', $this->input() + ['child_id' => '999', 'given_by' => '999']);
        self::assertSame(303, $response->status);
        self::assertSame('/children/9?tab=immunization', $response->headers['Location']);
        self::assertFalse($this->active);
    }

    public function test_AC07_AC08_profile_shows_all_four_statuses_and_configured_fic_completion(): void
    {
        $this->allow('ADMIN');
        $base = $this->vaccines[0]['doses'][0];
        foreach ([100, 101, 0] as $i => $age) {
            $id = $i + 20;
            $this->vaccines[] = ['id' => $id, 'name' => 'FAKE Vaccine ' . $id, 'active' => 1,
                'doses' => [array_replace($base, ['id' => $id, 'vaccine_id' => $id, 'recommended_age_days' => $age, 'counts_toward_fic' => 0])]];
        }
        $this->records->method('forChild')->willReturn([['schedule_dose_id' => 11, 'date_given' => $this->child['date_of_birth'],
            'given_elsewhere' => 1, 'place_given' => '<FAKE Hospital>', 'recorded_by' => '<FAKE Staff>', 'lot_number' => null, 'site' => null]]);
        $response = $this->dispatch('GET', '/children/9', [], ['tab' => 'immunization']);
        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        foreach (['Given', 'Due', 'Upcoming', 'Overdue', 'FIC: Complete', '&lt;FAKE Hospital&gt;', '&lt;FAKE Staff&gt;'] as $text) {
            self::assertStringContainsString($text, $response->body);
        }
        self::assertStringNotContainsString('<FAKE Hospital>', $response->body);
    }

    public function test_AC11_form_has_labeled_controls_csrf_and_escaped_values(): void
    {
        $this->allow();
        $response = $this->dispatch('GET', '/children/9/immunizations/new', [], ['dose' => '11']);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('&lt;FAKE &amp; Child&gt;', $response->body);
        self::assertStringContainsString('&lt;FAKE &amp; Vaccine&gt;', $response->body);
        $document = new DOMDocument();
        @$document->loadHTML($response->body);
        $xpath = new DOMXPath($document);
        self::assertSame(1, $xpath->query('//form/input[@name="_csrf_token"]')->length);
        foreach ($xpath->query('//form//input[not(@type="hidden")] | //form//select') as $input) {
            self::assertSame(1, $xpath->query('//label[@for="' . $input->getAttribute('id') . '"]')->length);
        }
        self::assertSame(1, $xpath->query('//option[@value="11" and @selected]')->length);
    }

    #[DataProvider('businessFailures')]
    public function test_AC11_business_failures_never_create_health_records(string $failure): void
    {
        $this->allow();
        $input = $this->input();
        if ($failure === 'archived') {
            $this->child['archived_at'] = '2026-01-01';
        } elseif ($failure === 'before_birth') {
            $input['date_given'] = (new DateTimeImmutable($this->child['date_of_birth']))->modify('-1 day')->format('Y-m-d');
        } elseif ($failure === 'inactive') {
            $this->vaccines[0]['active'] = 0;
        } elseif ($failure === 'missing_dose') {
            $this->vaccines[0]['doses'] = [];
        } elseif ($failure === 'missing_previous') {
            $this->vaccines[0]['doses'][0]['dose_number'] = 2;
        } elseif ($failure === 'too_early') {
            $this->vaccines[0]['doses'][0]['recommended_age_days'] = 101;
        } else {
            $this->records->method('forChild')->willReturn([['schedule_dose_id' => 11, 'date_given' => date('Y-m-d')]]);
        }
        $this->records->expects(self::never())->method('insert');
        $this->audit->expects(self::never())->method('insert');
        $response = $this->dispatch('POST', '/children/9/immunizations', $input);
        self::assertSame($failure === 'archived' ? 409 : 422, $response->status);
        self::assertFalse($this->active);
    }

    public static function businessFailures(): array
    {
        return [['archived'], ['before_birth'], ['inactive'], ['missing_dose'], ['missing_previous'], ['too_early'], ['duplicate']];
    }

    public function test_AC11_invalid_post_retains_escaped_input_without_starting_transaction(): void
    {
        $this->allow();
        $this->pdo->expects(self::never())->method('beginTransaction');
        $response = $this->dispatch('POST', '/children/9/immunizations', array_replace($this->input(), ['date_given' => 'invalid']));
        self::assertSame(422, $response->status);
        self::assertStringContainsString('value="&lt;FAKE Hospital&gt;"', $response->body);
        self::assertStringContainsString('id="immunization-error"', $response->body);
    }

    public function test_AC11_audit_failure_rolls_back_and_never_commits(): void
    {
        $this->records->method('insert')->willReturn(15);
        $this->audit->method('insert')->willThrowException(new RuntimeException('FAKE audit failure'));
        $this->pdo->expects(self::never())->method('commit');
        $this->pdo->expects(self::once())->method('rollBack');
        $this->expectException(RuntimeException::class);
        $this->service->record('9', $this->input(), 42);
    }

    public function test_AC11_minimum_interval_is_checked_against_date_given_including_elsewhere(): void
    {
        $base = $this->vaccines[0]['doses'][0];
        $this->vaccines[0]['doses'][] = array_replace($base, ['id' => 10]);
        $this->vaccines[0]['doses'][0]['dose_number'] = 2;
        $this->vaccines[0]['doses'][0]['min_interval_days'] = 28;
        $this->records->method('forChild')->willReturn([['schedule_dose_id' => 10, 'date_given' => date('Y-m-d', strtotime('-10 days'))]]);
        $this->records->expects(self::never())->method('insert');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('configured earliest date');
        $this->service->record('9', $this->input(), 42);
    }

    public function test_AC11_missing_or_malformed_child_ids_do_not_create_records(): void
    {
        $this->allow();
        $this->records->expects(self::never())->method('insert');
        foreach (['999', '0', 'invalid', '4294967296'] as $id) {
            self::assertSame(404, $this->dispatch('GET', '/children/' . $id . '/immunizations/new')->status);
            self::assertSame(404, $this->dispatch('POST', '/children/' . $id . '/immunizations', $this->input())->status);
        }
        self::assertFalse($this->active);
    }

    public function test_AC11_database_duplicate_errors_are_safe_and_roll_back(): void
    {
        $this->allow();
        $error = new PDOException('FAKE SQL detail');
        $error->errorInfo = ['23000', 1062, 'FAKE SQL detail'];
        $this->records->method('insert')->willThrowException($error);
        $this->audit->expects(self::never())->method('insert');
        $response = $this->dispatch('POST', '/children/9/immunizations', $this->input());
        self::assertSame(422, $response->status);
        self::assertStringContainsString('already recorded', $response->body);
        self::assertStringNotContainsString('FAKE SQL detail', $response->body);
        self::assertFalse($this->active);
    }

    public function test_AC12_archived_child_history_is_readable_but_record_actions_are_hidden(): void
    {
        $this->allow('ADMIN');
        $this->child['archived_at'] = '2026-01-01';
        $profile = $this->dispatch('GET', '/children/9', [], ['tab' => 'immunization']);
        self::assertSame(200, $profile->status);
        self::assertStringContainsString('Overdue', $profile->body);
        self::assertStringNotContainsString('/immunizations/new', $profile->body);
        self::assertSame(409, $this->dispatch('GET', '/children/9/immunizations/new')->status);
    }

    public function test_AC11_empty_schedule_has_guidance_and_no_unusable_form(): void
    {
        $this->allow();
        $this->vaccines = [];
        $page = $this->dispatch('GET', '/children/9/immunizations/new');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('No active unrecorded doses', $page->body);
        self::assertStringNotContainsString('id="immunization-form"', $page->body);
    }
}
