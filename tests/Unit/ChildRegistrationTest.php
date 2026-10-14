<?php

declare(strict_types=1);

use Pediacare\Controllers\AuthController;
use Pediacare\Controllers\ChildController;
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
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Repositories\RecordCounterRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\AuthService;
use Pediacare\Services\ChildService;
use Pediacare\Services\PurokService;
use Pediacare\Support\AuditLogger;
use Pediacare\Support\DuplicateConfirmation;
use Pediacare\Support\RecordNumberGenerator;
use Pediacare\Validation\ChildValidator;
use Pediacare\Validation\ChildFilterValidator;
use Pediacare\Validation\PurokValidator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ChildRegistrationTest extends TestCase
{
    private array $previousSession;
    private UserRepository $users;
    private ChildRepository $children;
    private PurokRepository $puroks;
    private AuditRepository $audit;
    private Router $router;
    private Csrf $csrf;
    private \Pediacare\Repositories\MeasurementRepository $measurements;
    private \Pediacare\Repositories\WhoLmsRepository $references;
    private \Pediacare\Repositories\VisitRepository $visits;
    private \Pediacare\Repositories\SupplementRepository $supplements;
    private \Pediacare\Repositories\ImmunizationRepository $timelineVaccines;
    private bool $active = false;

    protected function setUp(): void
    {
        $this->previousSession = $_SESSION ?? [];
        $_SESSION = ['user_id' => 42];
        $this->users = $this->createMock(UserRepository::class);
        $this->children = $this->createMock(ChildRepository::class);
        $this->puroks = $this->createMock(PurokRepository::class);
        $this->audit = $this->createMock(AuditRepository::class);
        $this->measurements = $this->createMock(\Pediacare\Repositories\MeasurementRepository::class);
        $this->references = $this->createMock(\Pediacare\Repositories\WhoLmsRepository::class);
        $this->visits = $this->createMock(\Pediacare\Repositories\VisitRepository::class);
        $this->supplements = $this->createMock(\Pediacare\Repositories\SupplementRepository::class);
        $this->timelineVaccines = $this->createMock(\Pediacare\Repositories\ImmunizationRepository::class);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturnCallback(fn (): bool => $this->active);
        $pdo->method('beginTransaction')->willReturnCallback(function (): bool { $this->active = true; return true; });
        $pdo->method('commit')->willReturnCallback(function (): bool { $this->active = false; return true; });
        $pdo->method('rollBack')->willReturnCallback(function (): bool { $this->active = false; return true; });
        $service = new ChildService($pdo, $this->children, $this->puroks, new ChildValidator(),
            new RecordNumberGenerator($this->createMock(RecordCounterRepository::class)), new AuditLogger($this->audit));
        $auth = new AuthService($this->users, $this->createMock(LoginAttemptRepository::class), new Session());
        $this->csrf = new Csrf($_SESSION);
        $flash = new Flash($_SESSION);
        $confirmation = new DuplicateConfirmation($_SESSION);
        $view = new View(dirname(__DIR__, 2) . '/views');
        $container = new Container();
        $container->register(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($auth));
        $container->register(RoleMiddleware::class, static fn (): RoleMiddleware => new RoleMiddleware($auth));
        $container->register(AuthController::class, fn (): AuthController => new AuthController($auth, $this->csrf, $view));
        $container->register(ChildController::class, fn (): ChildController => new ChildController(
            $service, new PurokService($this->puroks, new PurokValidator()), $auth, $this->csrf, $flash, $confirmation,
            $view, new ChildFilterValidator(), new \Pediacare\Services\GrowthHistoryService(
                $this->measurements, $this->references
            ), new \Pediacare\Services\ImmunizationService($pdo, $this->children,
                $this->createMock(\Pediacare\Repositories\VaccineScheduleRepository::class),
                $this->createMock(\Pediacare\Repositories\ImmunizationRepository::class),
                new \Pediacare\Validation\ImmunizationValidator(), new AuditLogger($this->audit)),
            new \Pediacare\Services\HealthTimelineService($this->measurements, $this->timelineVaccines, $this->visits, $this->supplements)
        ));
        $this->router = new Router($container, require dirname(__DIR__, 2) . '/src/routes.php');
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->previousSession;
    }

    #[DataProvider('recordReaders')]
    public function test_AC03_T33_timeline_shows_escaped_visit_details_and_retains_tab_in_pagination(string $role): void
    {
        $this->allow($role);
        $this->children->method('findById')->willReturn($this->profileChild());
        $rows = [];
        for ($id = 1; $id <= 26; ++$id) {
            $rows[] = ['id' => $id, 'visit_date' => '2026-01-01', 'reason' => '<FAKE Visit ' . $id . '>',
                'referred' => 1, 'referred_to' => '<FAKE Referral>', 'notes' => '<script>FAKE</script>',
                'findings' => '<FAKE Findings>', 'actions' => '<FAKE Actions>', 'attended_by_name' => '<FAKE Attendant>'];
        }
        $this->visits->method('forChild')->with(9)->willReturn($rows);
        $this->supplements->method('forChild')->with(9)->willReturn([
            ['visit_id' => 26, 'type' => 'IRON', 'dose' => '<FAKE dose>', 'date_given' => '2026-01-01']]);
        $this->references->expects(self::never())->method('load');
        $this->audit->expects(self::never())->method('insert');
        $response = $this->dispatch('GET', '/children/9', [], ['tab' => 'visits']);
        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        foreach (['&lt;FAKE Visit 26&gt;', '&lt;FAKE Referral&gt;', '&lt;FAKE Findings&gt;',
            '&lt;FAKE Actions&gt;', '&lt;FAKE Attendant&gt;', '&lt;FAKE dose&gt;', '&lt;script&gt;FAKE&lt;/script&gt;'] as $text) {
            self::assertStringContainsString($text, $response->body);
        }
        self::assertStringNotContainsString('<script>FAKE</script>', $response->body);
        self::assertStringContainsString('/children/9?tab=visits&amp;page=2', $response->body);
        $document = new DOMDocument();
        @$document->loadHTML($response->body);
        $xpath = new DOMXPath($document);
        self::assertSame(25, $xpath->query('//ol[@class="health-timeline"]/li')->length);
        $last = $this->dispatch('GET', '/children/9', [], ['tab' => 'visits', 'page' => '2']);
        self::assertStringContainsString('&lt;FAKE Visit 1&gt;', $last->body);
        self::assertStringContainsString('/children/9?tab=visits&amp;page=1', $last->body);
        self::assertStringNotContainsString('&lt;FAKE dose&gt;', $last->body);
    }

    public function test_AC12_T33_archived_profile_retains_timeline_but_hides_new_visit_action(): void
    {
        $this->allow('ADMIN');
        $child = $this->profileChild();
        $child['archived_at'] = '2026-01-01';
        $child['archive_reason'] = 'FAKE archived';
        $this->children->method('findById')->willReturn($child);
        $this->visits->method('forChild')->willReturn([
            ['id' => 1, 'visit_date' => '2026-01-01', 'reason' => 'FAKE retained history', 'referred' => 0]]);
        $response = $this->dispatch('GET', '/children/9', [], ['tab' => 'visits']);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('FAKE retained history', $response->body);
        self::assertStringNotContainsString('/children/9/visits/new', $response->body);
    }

    public function test_AC03_T33_invalid_timeline_page_has_safe_error_and_does_not_load_history(): void
    {
        $this->allow('BHW');
        $this->children->method('findById')->willReturn($this->profileChild());
        $this->measurements->expects(self::never())->method('history');
        $this->visits->expects(self::never())->method('forChild');
        $response = $this->dispatch('GET', '/children/9', [], ['tab' => 'visits', 'page' => []]);
        self::assertSame(422, $response->status);
        self::assertSame('Choose a valid health timeline page.', $response->body);
    }

    private function dispatch(string $method, string $path, array $post = [], array $query = []): Response
    {
        return (new CsrfMiddleware($this->csrf))->handle(new Request($method, $path, $query, $post),
            fn (Request $request): Response => $this->router->dispatch($request));
    }

    private function allow(string $role): void
    {
        $this->users->method('findById')->willReturn(['id' => 42, 'name' => 'FAKE Staff', 'role' => $role, 'active' => 1]);
        $this->puroks->method('all')->willReturn([['id' => 7, 'name' => '<FAKE Purok>']]);
        $this->puroks->method('findById')->willReturn(['id' => 7]);
    }

    public function test_AC01_logged_out_registration_redirects_to_login(): void
    {
        $this->users->method('findById')->willReturn(null);
        self::assertSame('/login', $this->dispatch('GET', '/children/new')->headers['Location']);
    }

    public function test_AC03_viewer_cannot_access_form_or_register_and_ui_is_hidden(): void
    {
        $this->allow('VIEWER');
        $this->children->expects(self::never())->method('insert');
        self::assertSame(403, $this->dispatch('GET', '/children/new')->status);
        self::assertSame(403, $this->dispatch('POST', '/children', ['_csrf_token' => $this->csrf->token()])->status);
        self::assertStringNotContainsString('/children/new', $this->dispatch('GET', '/login')->body);
    }

    public function test_AC04_form_has_labels_csrf_and_escapes_demographics_on_validation_failure(): void
    {
        $this->allow('STAFF');
        $response = $this->dispatch('GET', '/children/new');
        self::assertSame(200, $response->status);
        self::assertStringContainsString('&lt;FAKE Purok&gt;', $response->body);
        $document = new DOMDocument();
        @$document->loadHTML($response->body);
        $xpath = new DOMXPath($document);
        self::assertSame(1, $xpath->query('//form/input[@name="_csrf_token"]')->length);
        foreach ($xpath->query('//input[not(@type="hidden")]|//select|//textarea') as $control) {
            self::assertSame(1, $xpath->query('//label[@for="' . $control->getAttribute('id') . '"]')->length);
        }
        $invalid = array_replace(require dirname(__DIR__) . '/Fixtures/child.php', [
            '_csrf_token' => $this->csrf->token(), 'first_name' => '<script>FAKE</script>', 'sex' => 'X',
        ]);
        $error = $this->dispatch('POST', '/children', $invalid);
        self::assertSame(422, $error->status);
        self::assertStringContainsString('&lt;script&gt;FAKE&lt;/script&gt;', $error->body);
        self::assertStringNotContainsString('<script>FAKE', $error->body);
    }

    public function test_AC02_bhw_can_register_with_csrf_and_post_redirect_get(): void
    {
        $this->allow('BHW');
        $this->children->expects(self::once())->method('insert')->willReturn(9);
        $this->audit->expects(self::once())->method('insert');
        $this->children->method('findById')->willReturn($this->profileChild());
        $input = require dirname(__DIR__) . '/Fixtures/child.php';
        self::assertSame(403, $this->dispatch('POST', '/children', $input)->status);
        $input['_csrf_token'] = $this->csrf->token();
        $result = $this->dispatch('POST', '/children', $input);
        self::assertSame(303, $result->status);
        self::assertSame('/children/9', $result->headers['Location']);
        self::assertStringContainsString('Child registered. Record number:', $this->dispatch('GET', '/children/9')->body);
        self::assertStringNotContainsString('Child registered. Record number:', $this->dispatch('GET', '/children/9')->body);
    }

    public function test_AC04_duplicate_requires_issued_confirmation_and_changing_details_warns_again(): void
    {
        $this->allow('ADMIN');
        $this->children->method('duplicates')->willReturn([['id' => 1, 'record_no' => '<FAKE Record>', 'archived_at' => '2026-01-01']]);
        $this->children->expects(self::once())->method('insert')->willReturn(9);
        $input = require dirname(__DIR__) . '/Fixtures/child.php';
        $input['_csrf_token'] = $this->csrf->token();
        $input['confirm_duplicate'] = '1';
        $warning = $this->dispatch('POST', '/children', $input);
        self::assertSame(409, $warning->status);
        self::assertStringContainsString('Possible duplicate', $warning->body);
        self::assertStringContainsString('&lt;FAKE Record&gt;</a> (archived)', $warning->body);
        self::assertStringContainsString('href="/children/1"', $warning->body);
        preg_match('/name="duplicate_token" value="([a-f0-9]+)"/', $warning->body, $match);
        $input['duplicate_token'] = $match[1];
        $input['address'] = 'FAKE Changed Address';
        $changed = $this->dispatch('POST', '/children', $input);
        self::assertSame(409, $changed->status);
        preg_match('/name="duplicate_token" value="([a-f0-9]+)"/', $changed->body, $match);
        $input['duplicate_token'] = $match[1];
        self::assertSame(303, $this->dispatch('POST', '/children', $input)->status);
        self::assertArrayNotHasKey('_child_duplicate_confirmation', $_SESSION);
    }

    public function test_AC04_no_puroks_disables_registration_with_setup_guidance(): void
    {
        $this->users->method('findById')->willReturn(['id' => 42, 'role' => 'BHW', 'active' => 1]);
        self::assertStringContainsString('Ask an Admin to add a purok', $this->dispatch('GET', '/children/new')->body);
        self::assertStringContainsString('type="submit" disabled', $this->dispatch('GET', '/children/new')->body);
    }

    public function test_AC01_logged_out_child_search_redirects_without_reading_children(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->children->expects(self::never())->method('count');
        self::assertSame('/login', $this->dispatch('GET', '/children')->headers['Location']);
    }

    public function test_AC03_viewer_cannot_search_children_and_navigation_is_hidden(): void
    {
        $this->allow('VIEWER');
        $this->children->expects(self::never())->method('count');
        $response = $this->dispatch('GET', '/children', [], ['include_archived' => '1']);
        self::assertSame(403, $response->status);
        self::assertStringNotContainsString('FAKE Family', $response->body);
        self::assertStringNotContainsString('href="/children"', $this->dispatch('GET', '/login')->body);
    }

    #[DataProvider('recordReaders')]
    public function test_AC12_authorized_roles_can_search_with_default_archive_exclusion(string $role): void
    {
        $this->allow($role);
        $this->children->expects(self::once())->method('count')->with(
            self::callback(static fn (array $filters): bool => !$filters['include_archived']), date('Y-m-d')
        )->willReturn(0);
        $this->children->expects(self::once())->method('search')->with(
            self::anything(), date('Y-m-d'), 25, 0
        )->willReturn([]);
        $response = $this->dispatch('GET', '/children');
        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertStringContainsString('No children match', $response->body);
        self::assertStringContainsString('href="/children"', $this->dispatch('GET', '/login')->body);
    }

    public static function recordReaders(): array
    {
        return [['ADMIN'], ['STAFF'], ['BHW']];
    }

    public function test_AC12_child_list_escapes_data_and_preserves_filters_across_pages(): void
    {
        $this->allow('BHW');
        $this->children->method('count')->willReturn(60);
        $this->children->expects(self::once())->method('search')->with(
            self::callback(static fn (array $filters): bool => $filters['q'] === '<FAKE & child>'
                && $filters['purok_id'] === '7' && $filters['sex'] === 'F'
                && $filters['age_group'] === '12-23' && $filters['include_archived']), date('Y-m-d'), 25, 25
        )->willReturn([['id' => 1, 'record_no' => 'PC-2026-00001', 'full_name' => '<script>FAKE</script>',
            'sex' => 'F', 'date_of_birth' => '2025-01-01', 'age_months' => 21,
            'guardian_name' => '<FAKE Guardian>', 'purok_name' => '<FAKE Purok>', 'archived_at' => '2026-01-01']]);
        $response = $this->dispatch('GET', '/children', [], ['q' => '<FAKE & child>', 'purok_id' => '7',
            'sex' => 'F', 'age_group' => '12-23', 'include_archived' => '1', 'page' => '2']);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('&lt;script&gt;FAKE&lt;/script&gt;', $response->body);
        self::assertStringNotContainsString('<script>FAKE', $response->body);
        self::assertStringContainsString('Archived', $response->body);
        self::assertStringContainsString('Showing 26–50 of 60', $response->body);
        self::assertStringContainsString('href="/children/1"', $response->body);
        $document = new DOMDocument();
        @$document->loadHTML($response->body);
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//form[@action="/children"]//input|//form[@action="/children"]//select') as $control) {
            self::assertSame(1, $xpath->query('//label[@for="' . $control->getAttribute('id') . '"]')->length);
        }
        self::assertSame(1, $xpath->query('//table/caption')->length);
        foreach (['prev' => '1', 'next' => '3'] as $rel => $page) {
            $link = $xpath->query('//nav[@aria-label="Children pages"]/a[@rel="' . $rel . '"]')->item(0);
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame(['q' => '<FAKE & child>', 'purok_id' => '7', 'sex' => 'F',
                'age_group' => '12-23', 'include_archived' => '1', 'page' => $page], $query);
        }
    }

    public function test_AC12_out_of_range_pages_use_last_page_and_invalid_filters_do_not_query(): void
    {
        $this->allow('STAFF');
        $this->children->expects(self::once())->method('count')->willReturn(26);
        $this->children->expects(self::once())->method('search')->with(self::anything(), date('Y-m-d'), 25, 25)->willReturn([]);
        self::assertSame(200, $this->dispatch('GET', '/children', [], ['page' => '2147483647'])->status);
        $error = $this->dispatch('GET', '/children', [], ['sex' => ['F']]);
        self::assertSame(422, $error->status);
        self::assertStringContainsString('role="alert"', $error->body);
        self::assertStringNotContainsString('<table', $error->body);
    }

    private function profileChild(array $changes = []): array
    {
        $data = (new ChildValidator())->validate(require dirname(__DIR__) . '/Fixtures/child.php');

        return array_replace($data, ['id' => 9, 'record_no' => 'PC-2026-00009',
            'full_name' => 'FAKE Child FAKE Family', 'age_months' => 12, 'purok_name' => 'FAKE Purok',
            'archived_at' => null, 'archive_reason' => null], $changes);
    }

    public function test_AC01_logged_out_profile_redirects_before_loading_demographics(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->children->expects(self::never())->method('findById');
        $this->measurements->expects(self::never())->method('history');
        $this->references->expects(self::never())->method('load');
        $this->visits->expects(self::never())->method('forChild');
        $this->supplements->expects(self::never())->method('forChild');
        $this->timelineVaccines->expects(self::never())->method('forChild');
        self::assertSame('/login', $this->dispatch('GET', '/children/9')->headers['Location']);
        self::assertSame('/login', $this->dispatch('GET', '/children/9', [], ['tab' => 'visits'])->headers['Location']);
    }

    public function test_AC03_viewer_is_denied_every_profile_tab_before_any_child_query(): void
    {
        $this->allow('VIEWER');
        $this->children->expects(self::never())->method('findById');
        $this->measurements->expects(self::never())->method('history');
        $this->references->expects(self::never())->method('load');
        $this->visits->expects(self::never())->method('forChild');
        $this->supplements->expects(self::never())->method('forChild');
        $this->timelineVaccines->expects(self::never())->method('forChild');
        foreach (['growth', 'immunization', 'visits', '<script>FAKE</script>'] as $tab) {
            self::assertSame(403, $this->dispatch('GET', '/children/9', [], ['tab' => $tab])->status);
        }
        self::assertSame(403, $this->dispatch('GET', '/children/4294967295')->status);
    }

    #[DataProvider('recordReaders')]
    public function test_AC03_authorized_roles_can_open_demographics_and_all_health_tabs(string $role): void
    {
        $this->allow($role);
        $this->children->expects(self::exactly(3))->method('findById')->with(9, date('Y-m-d'))->willReturn($this->profileChild());
        $this->measurements->expects(self::exactly(2))->method('history')->with(9)->willReturn([]);
        $this->references->expects(self::never())->method('load');
        foreach (['growth' => 'Growth', 'immunization' => 'Immunization', 'visits' => 'Visits'] as $tab => $label) {
            $response = $this->dispatch('GET', '/children/9', [], ['tab' => $tab]);
            self::assertSame(200, $response->status);
            self::assertSame('no-store', $response->headers['Cache-Control']);
            self::assertStringContainsString('FAKE Child FAKE Family', $response->body);
            self::assertStringContainsString('FAKE Address', $response->body);
            self::assertStringContainsString('Not recorded', $response->body);
            if ($tab === 'growth') {
                self::assertStringContainsString('/children/9/measurements/new', $response->body);
            } elseif ($tab === 'visits') {
                self::assertStringContainsString('/children/9/visits/new', $response->body);
            } else {
                self::assertStringContainsString('No vaccine schedule configured.', $response->body);
            }
            $document = new DOMDocument();
            @$document->loadHTML($response->body);
            $xpath = new DOMXPath($document);
            $links = $xpath->query('//nav[@aria-label="Child health records"]/a');
            self::assertSame(3, $links->length);
            $selected = $xpath->query('//nav[@aria-label="Child health records"]/a[@aria-current="page"]');
            self::assertSame(1, $selected->length);
            self::assertSame($label, $selected->item(0)->textContent);
            self::assertSame('/children/9?tab=' . $tab, $selected->item(0)->getAttribute('href'));
            self::assertSame($role === 'BHW' ? 0 : 1, $xpath->query('//form[@method="post"]')->length);
        }
    }

    public function test_AC03_profile_escapes_all_demographics_and_archived_reason(): void
    {
        $this->allow('BHW');
        $child = $this->profileChild(['full_name' => '<FAKE Child>', 'record_no' => '<FAKE Record>',
            'birth_weight_kg' => '3.50', 'birth_length_cm' => '50.0', 'archived_at' => '2026-01-01 12:00:00',
            'archive_reason' => '<script>FAKE reason</script>']);
        foreach (['place_of_birth', 'address', 'mother_name', 'father_name', 'guardian_name', 'guardian_relation',
            'contact_no', 'philhealth_no', 'notes', 'purok_name'] as $field) {
            $child[$field] = '<script>FAKE ' . $field . '</script>';
        }
        $this->children->method('findById')->willReturn($child);
        $response = $this->dispatch('GET', '/children/9');
        self::assertSame(200, $response->status);
        self::assertStringContainsString('No measurements recorded yet.', $response->body);
        self::assertStringNotContainsString('/measurements/new', $response->body);
        self::assertStringContainsString('&lt;FAKE Child&gt;', $response->body);
        self::assertStringContainsString('&lt;FAKE Record&gt;', $response->body);
        self::assertStringNotContainsString('<script>FAKE', $response->body);
        self::assertStringContainsString('&lt;script&gt;FAKE reason&lt;/script&gt;', $response->body);
        self::assertStringContainsString('href="/children?include_archived=1"', $response->body);
        self::assertStringContainsString('3.50', $response->body);
        self::assertStringContainsString('50.0', $response->body);
        self::assertStringContainsString('age', strtolower($response->body));
        foreach (['place_of_birth', 'address', 'mother_name', 'father_name', 'guardian_name', 'guardian_relation',
            'contact_no', 'philhealth_no', 'notes', 'purok_name'] as $field) {
            self::assertStringContainsString('&lt;script&gt;FAKE ' . $field . '&lt;/script&gt;', $response->body);
        }
    }

    #[DataProvider('recordReaders')]
    public function test_AC03_AC06_AC12_growth_history_and_local_chart_are_available_on_active_and_archived_profiles(string $role): void
    {
        $this->allow($role);
        $this->children->method('findById')->willReturnOnConsecutiveCalls($this->profileChild(),
            $this->profileChild(['archived_at' => '2026-01-01 12:00:00', 'archive_reason' => 'FAKE archive']));
        $measurement = ['measured_at' => '2026-01-01', 'age_months' => 1, 'weight_kg' => '5.00',
            'height_cm' => '55.0', 'position' => 'LYING', 'muac_cm' => null, 'bmi' => '16.53',
            'implausible_confirmed' => 1, 'measured_by_name' => '<FAKE Staff>'];
        foreach (['wfa', 'hfa', 'wfh', 'bmi'] as $indicator) {
            $measurement[$indicator . '_z'] = null;
            $measurement[$indicator . '_status'] = null;
        }
        $this->measurements->expects(self::exactly(2))->method('history')->with(9)->willReturn([$measurement]);
        $this->references->expects(self::exactly(2))->method('load')->willReturn(require dirname(__DIR__) . '/Fixtures/who-lms.php');
        foreach ([false, true] as $archived) {
            $response = $this->dispatch('GET', '/children/9', [], ['tab' => 'growth']);
            self::assertSame(200, $response->status);
            self::assertSame('no-store', $response->headers['Cache-Control']);
            self::assertStringContainsString('&lt;FAKE Staff&gt;', $response->body);
            self::assertStringContainsString('Not available', $response->body);
            self::assertStringContainsString('Implausible values confirmed', $response->body);
            self::assertStringContainsString('/assets/vendor/chart.umd.min.js', $response->body);
            self::assertStringContainsString('/assets/js/growth.js', $response->body);
            if ($archived) {
                self::assertStringNotContainsString('/measurements/new', $response->body);
            } else {
                self::assertStringContainsString('/measurements/new', $response->body);
            }
        }
    }

    public function test_AC03_missing_and_invalid_child_ids_return_not_found_and_invalid_tabs_are_rejected(): void
    {
        $this->allow('STAFF');
        $this->children->expects(self::once())->method('findById')->with(9, date('Y-m-d'))->willReturn(null);
        $missing = $this->dispatch('GET', '/children/9');
        self::assertSame(404, $missing->status);
        self::assertSame('no-store', $missing->headers['Cache-Control']);
        foreach (['0', '-1', '4294967296', '1e2', '%27%20OR%201=1--', 'FAKE'] as $id) {
            self::assertSame(404, $this->dispatch('GET', '/children/' . $id)->status);
        }
        foreach ([['growth'], '<script>FAKE</script>', 'FAKE', ''] as $tab) {
            $invalid = $this->dispatch('GET', '/children/9', [], ['tab' => $tab]);
            self::assertSame(400, $invalid->status);
            self::assertStringNotContainsString('FAKE', $invalid->body);
        }
    }

    #[DataProvider('nonEditors')]
    public function test_AC02_bhw_and_viewer_cannot_edit_archive_or_restore_through_direct_requests(string $role): void
    {
        $this->allow($role);
        $this->children->expects(self::never())->method('lockById');
        $this->children->expects(self::never())->method('update');
        $this->children->expects(self::never())->method('setArchive');
        $this->audit->expects(self::never())->method('insert');
        self::assertSame(403, $this->dispatch('GET', '/children/9/edit')->status);
        foreach (['update', 'archive', 'restore'] as $action) {
            self::assertSame(403, $this->dispatch('POST', '/children/9/' . $action,
                ['_csrf_token' => $this->csrf->token(), 'reason' => 'FAKE Reason', 'confirm' => '1'])->status);
        }
        if ($role === 'BHW') {
            $this->children->method('findById')->willReturn($this->profileChild());
            $profile = $this->dispatch('GET', '/children/9');
            self::assertStringNotContainsString('/children/9/edit', $profile->body);
            self::assertStringNotContainsString('/children/9/archive', $profile->body);
            self::assertStringNotContainsString('/children/9/restore', $profile->body);
        }
    }

    public static function nonEditors(): array
    {
        return [['BHW'], ['VIEWER']];
    }

    #[DataProvider('editors')]
    public function test_AC02_admin_and_staff_see_edit_and_status_forms_with_csrf_and_confirmations(string $role): void
    {
        $this->allow($role);
        $this->children->method('findById')->willReturnOnConsecutiveCalls($this->profileChild(),
            $this->profileChild(['archived_at' => '2026-01-01 12:00:00', 'archive_reason' => 'FAKE Reason']),
            $this->profileChild(['date_of_birth' => '2020-01-01', 'age_months' => 81]));
        foreach (['archive', 'restore'] as $action) {
            $response = $this->dispatch('GET', '/children/9');
            self::assertSame(200, $response->status);
            self::assertStringContainsString('href="/children/9/edit"', $response->body);
            $document = new DOMDocument();
            @$document->loadHTML($response->body);
            $xpath = new DOMXPath($document);
            self::assertSame(1, $xpath->query('//details//form[@action="/children/9/' . $action . '"]')->length);
            self::assertSame(1, $xpath->query('//form/input[@name="_csrf_token"]')->length);
            self::assertSame(1, $xpath->query('//textarea[@name="reason" and @required]')->length);
            self::assertSame(1, $xpath->query('//input[@name="confirm" and @required]')->length);
        }
        $edit = $this->dispatch('GET', '/children/9/edit');
        self::assertSame(200, $edit->status);
        self::assertSame('no-store', $edit->headers['Cache-Control']);
        self::assertStringContainsString('action="/children/9/update"', $edit->body);
        self::assertStringContainsString('value="7" selected', $edit->body);
        self::assertStringContainsString('value="2020-01-01"', $edit->body);
        self::assertStringNotContainsString('aged 0–59', $edit->body);
        self::assertStringNotContainsString('name="record_no"', $edit->body);
    }

    public static function editors(): array
    {
        return [['ADMIN'], ['STAFF']];
    }

    public function test_AC11_edit_requires_csrf_retains_input_on_error_and_redirects_after_audited_save(): void
    {
        $this->allow('STAFF');
        $child = $this->profileChild();
        $this->children->method('findById')->willReturn($child);
        $this->children->method('lockById')->willReturn($child);
        $this->children->expects(self::once())->method('update')->with(9,
            self::callback(static fn (array $data): bool => $data['first_name'] === '<FAKE Changed>'
                && !isset($data['record_no']) && !isset($data['created_by']) && !isset($data['archived_at'])));
        $this->audit->expects(self::once())->method('insert')->with(42, 'UPDATE', 'children', 9,
            self::callback(static fn (string $changes): bool => json_decode($changes, true)['after']['first_name'] === '<FAKE Changed>'), null);
        $input = require dirname(__DIR__) . '/Fixtures/child.php';
        self::assertSame(403, $this->dispatch('POST', '/children/9/update', $input)->status);
        $input['_csrf_token'] = $this->csrf->token();
        $input['first_name'] = '<FAKE Changed>';
        $input['sex'] = 'X';
        $error = $this->dispatch('POST', '/children/9/update', $input);
        self::assertSame(422, $error->status);
        self::assertStringContainsString('&lt;FAKE Changed&gt;', $error->body);
        $input['sex'] = 'F';
        $input += ['record_no' => 'FORGED', 'created_by' => '999', 'archived_at' => '2026-01-01'];
        $saved = $this->dispatch('POST', '/children/9/update', $input);
        self::assertSame(303, $saved->status);
        self::assertSame('/children/9', $saved->headers['Location']);
        self::assertStringContainsString('Child details saved.', $this->dispatch('GET', '/children/9')->body);
    }

    public function test_AC11_archive_and_restore_require_csrf_reason_confirmation_and_redirect(): void
    {
        $this->allow('ADMIN');
        $state = $this->profileChild();
        $this->children->method('findById')->willReturnCallback(static function () use (&$state): array { return $state; });
        $this->children->method('lockById')->willReturnCallback(static function () use (&$state): array { return $state; });
        $this->children->expects(self::exactly(2))->method('setArchive')->willReturnCallback(
            static function (int $id, ?string $at, ?string $reason) use (&$state): void {
                $state['archived_at'] = $at;
                $state['archive_reason'] = $reason;
            }
        );
        $this->audit->expects(self::exactly(2))->method('insert');
        foreach (['archive', 'restore'] as $action) {
            $path = '/children/9/' . $action;
            self::assertSame(403, $this->dispatch('POST', $path, ['reason' => 'FAKE Reason', 'confirm' => '1'])->status);
            $token = ['_csrf_token' => $this->csrf->token()];
            self::assertSame(422, $this->dispatch('POST', $path, $token + ['reason' => 'FAKE Reason'])->status);
            self::assertSame(422, $this->dispatch('POST', $path, $token + ['confirm' => '1', 'reason' => ' '])->status);
            $saved = $this->dispatch('POST', $path, $token + ['confirm' => '1', 'reason' => 'FAKE Reason']);
            self::assertSame(303, $saved->status);
            self::assertSame('/children/9', $saved->headers['Location']);
        }
    }

    public function test_AC01_logged_out_edit_and_confirmed_status_changes_redirect_to_login(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->children->expects(self::never())->method('lockById');
        self::assertSame('/login', $this->dispatch('GET', '/children/9/edit')->headers['Location']);
        foreach (['update', 'archive', 'restore'] as $action) {
            self::assertSame('/login', $this->dispatch('POST', '/children/9/' . $action,
                ['_csrf_token' => $this->csrf->token()])->headers['Location']);
        }
    }

    public function test_AC11_missing_child_edit_and_status_changes_return_404_without_writes(): void
    {
        $this->allow('STAFF');
        $this->children->expects(self::once())->method('findById')->with(9, date('Y-m-d'))->willReturn(null);
        $this->children->expects(self::exactly(3))->method('lockById')->with(9)->willReturn(null);
        $this->children->expects(self::never())->method('update');
        $this->children->expects(self::never())->method('setArchive');
        $this->audit->expects(self::never())->method('insert');
        self::assertSame(404, $this->dispatch('GET', '/children/9/edit')->status);
        foreach (['update', 'archive', 'restore'] as $action) {
            $input = $action === 'update' ? require dirname(__DIR__) . '/Fixtures/child.php'
                : ['reason' => 'FAKE Reason', 'confirm' => '1'];
            $input['_csrf_token'] = $this->csrf->token();
            $response = $this->dispatch('POST', '/children/9/' . $action, $input);
            self::assertSame(404, $response->status);
            self::assertSame('no-store', $response->headers['Cache-Control']);
        }
    }
}
