<?php

declare(strict_types=1);

use Pediacare\Controllers\VisitController;
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
use Pediacare\Repositories\SupplementRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Repositories\VisitRepository;
use Pediacare\Services\AuthService;
use Pediacare\Services\ChildService;
use Pediacare\Services\VisitService;
use Pediacare\Support\AuditLogger;
use Pediacare\Support\RecordNumberGenerator;
use Pediacare\Validation\ChildValidator;
use Pediacare\Validation\VisitValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VisitWorkflowTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $users;
    private ChildRepository $children;
    private VisitRepository $visits;
    private SupplementRepository $supplements;
    private AuditRepository $audit;
    private VisitService $service;
    private Csrf $csrf;
    private Router $router;
    private bool $active = false;
    private array $previousSession;
    private array $child;

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
        $this->visits = $this->createMock(VisitRepository::class);
        $this->supplements = $this->createMock(SupplementRepository::class);
        $this->audit = $this->createMock(AuditRepository::class);
        $this->child = ['id' => 9, 'date_of_birth' => date('Y-m-d', strtotime('-100 days')), 'archived_at' => null,
            'full_name' => '<FAKE & Child>', 'record_no' => 'PC-2099-00009'];
        $this->children->method('findById')->willReturnCallback(fn (int $id): ?array => $id === 9 ? $this->child : null);
        $this->children->method('lockById')->willReturnCallback(fn (int $id): ?array => $id === 9 ? $this->child : null);
        $this->service = new VisitService($this->pdo, $this->children, $this->visits, $this->supplements,
            new VisitValidator(), new AuditLogger($this->audit));
        $auth = new AuthService($this->users, $this->createMock(LoginAttemptRepository::class), new Session());
        $this->csrf = new Csrf($_SESSION);
        $flash = new Flash($_SESSION);
        $view = new View(dirname(__DIR__, 2) . '/views');
        $childService = new ChildService($this->pdo, $this->children, $this->createMock(PurokRepository::class),
            new ChildValidator(), new RecordNumberGenerator($this->createMock(RecordCounterRepository::class)), new AuditLogger($this->audit));
        $container = new Container();
        $container->register(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($auth));
        $container->register(RoleMiddleware::class, static fn (): RoleMiddleware => new RoleMiddleware($auth));
        $container->register(VisitController::class, fn (): VisitController => new VisitController(
            $this->service, $childService, $auth, $this->csrf, $flash, $view
        ));
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
        return ['visit_date' => date('Y-m-d'), 'reason' => '<FAKE Visit>', 'referred' => '1',
            'referred_to' => '<FAKE Facility>', '_csrf_token' => $this->csrf->token(),
            'supplements' => ['VITAMIN_A' => ['dose' => 'FAKE 1 unit', 'date_given' => date('Y-m-d')]]];
    }

    private function dispatch(string $method, string $path, array $post = []): Response
    {
        return (new CsrfMiddleware($this->csrf))->handle(new Request($method, $path, [], $post),
            fn (Request $request): Response => $this->router->dispatch($request));
    }

    public function test_AC01_logged_out_routes_redirect_before_loading_health_data(): void
    {
        $this->children->expects(self::never())->method('findById');
        $this->children->expects(self::never())->method('lockById');
        self::assertSame(303, $this->dispatch('GET', '/children/9/visits/new')->status);
        self::assertSame(303, $this->dispatch('POST', '/children/9/visits', $this->input())->status);
    }

    public function test_AC03_viewer_cannot_read_or_record_individual_visits(): void
    {
        $this->allow('VIEWER');
        $this->children->expects(self::never())->method('findById');
        $this->pdo->expects(self::never())->method('beginTransaction');
        self::assertSame(403, $this->dispatch('GET', '/children/9/visits/new')->status);
        self::assertSame(403, $this->dispatch('POST', '/children/9/visits', $this->input())->status);
    }

    #[DataProvider('writers')]
    public function test_AC11_admin_staff_and_bhw_get_labeled_escaped_form(string $role): void
    {
        $this->allow($role);
        $response = $this->dispatch('GET', '/children/9/visits/new');
        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertStringContainsString('&lt;FAKE &amp; Child&gt;', $response->body);
        self::assertStringContainsString('&lt;FAKE &amp; Staff&gt;', $response->body);
        $document = new DOMDocument();
        @$document->loadHTML($response->body);
        $xpath = new DOMXPath($document);
        self::assertSame(1, $xpath->query('//form/input[@name="_csrf_token"]')->length);
        foreach ($xpath->query('//form//input[not(@type="hidden")] | //form//select | //form//textarea') as $input) {
            self::assertSame(1, $xpath->query('//label[@for="' . $input->getAttribute('id') . '"]')->length);
        }
        self::assertSame(4, $xpath->query('//form//fieldset[@aria-describedby="supplement-help"]')->length);
    }

    public static function writers(): array
    {
        return [['ADMIN'], ['STAFF'], ['BHW']];
    }

    public function test_AC11_csrf_rejection_does_not_start_transaction(): void
    {
        $this->allow();
        $this->pdo->expects(self::never())->method('beginTransaction');
        self::assertSame(403, $this->dispatch('POST', '/children/9/visits', array_replace($this->input(), ['_csrf_token' => 'INVALID']))->status);
    }

    #[DataProvider('writers')]
    public function test_AC11_visit_and_supplement_are_audited_with_authenticated_actor_inside_transaction(string $role): void
    {
        $this->allow($role);
        $this->visits->expects(self::once())->method('insert')->willReturnCallback(function (array $data): int {
            self::assertTrue($this->active);
            self::assertSame(9, $data['child_id']);
            self::assertSame(42, $data['attended_by']);
            self::assertSame('<FAKE Visit>', $data['reason']);
            return 15;
        });
        $this->supplements->expects(self::once())->method('insert')->with(['type' => 'VITAMIN_A',
            'dose' => 'FAKE 1 unit', 'date_given' => date('Y-m-d'), 'visit_id' => 15])->willReturn(16);
        $entities = [];
        $this->audit->expects(self::exactly(2))->method('insert')->willReturnCallback(function (int $actor, string $action,
            string $entity, int $id, string $changes) use (&$entities): void {
            self::assertTrue($this->active);
            self::assertSame(42, $actor);
            self::assertSame('CREATE', $action);
            $after = json_decode($changes, true, 512, JSON_THROW_ON_ERROR)['after'];
            self::assertNotEmpty($after);
            $entities[] = [$entity, $id];
        });
        $response = $this->dispatch('POST', '/children/9/visits', $this->input() + ['child_id' => '999', 'attended_by' => '999']);
        self::assertSame(303, $response->status);
        self::assertSame('/children/9?tab=visits', $response->headers['Location']);
        self::assertSame([['visits', 15], ['supplements', 16]], $entities);
        self::assertFalse($this->active);
    }

    public function test_AC11_validation_error_retains_visit_and_supplement_text_without_writing(): void
    {
        $this->allow();
        $this->pdo->expects(self::never())->method('beginTransaction');
        $input = $this->input();
        $input['supplements']['VITAMIN_A']['dose'] = '<FAKE dose>';
        $input['supplements']['VITAMIN_A']['date_given'] = '';
        $response = $this->dispatch('POST', '/children/9/visits', $input);
        self::assertSame(422, $response->status);
        self::assertStringContainsString('&lt;FAKE Visit&gt;', $response->body);
        self::assertStringContainsString('&lt;FAKE dose&gt;', $response->body);
        self::assertStringContainsString('id="child-error"', $response->body);
        self::assertStringNotContainsString('<FAKE dose>', $response->body);
    }

    public function test_AC11_AC12_archived_and_prebirth_dates_never_create_health_records(): void
    {
        $this->allow();
        $this->visits->expects(self::never())->method('insert');
        $this->supplements->expects(self::never())->method('insert');
        $this->audit->expects(self::never())->method('insert');
        $input = $this->input();
        $before = (new DateTimeImmutable($this->child['date_of_birth']))->modify('-1 day')->format('Y-m-d');
        self::assertSame(422, $this->dispatch('POST', '/children/9/visits', array_replace($input, ['visit_date' => $before]))->status);
        $input['supplements']['VITAMIN_A']['date_given'] = $before;
        self::assertSame(422, $this->dispatch('POST', '/children/9/visits', $input)->status);
        $this->child['archived_at'] = '2026-01-01';
        self::assertSame(409, $this->dispatch('GET', '/children/9/visits/new')->status);
        self::assertSame(409, $this->dispatch('POST', '/children/9/visits', $this->input())->status);
        self::assertFalse($this->active);
    }

    public function test_AC11_missing_or_invalid_child_ids_never_create_records(): void
    {
        $this->allow();
        $this->visits->expects(self::never())->method('insert');
        foreach (['999', '0', 'invalid', '4294967296'] as $id) {
            self::assertSame(404, $this->dispatch('GET', '/children/' . $id . '/visits/new')->status);
            self::assertSame(404, $this->dispatch('POST', '/children/' . $id . '/visits', $this->input())->status);
        }
        self::assertFalse($this->active);
    }

    public function test_AC11_audit_failure_rolls_back_instead_of_committing_visit(): void
    {
        $this->visits->method('insert')->willReturn(15);
        $this->audit->method('insert')->willThrowException(new RuntimeException('FAKE audit failure'));
        $this->pdo->expects(self::never())->method('commit');
        $this->pdo->expects(self::once())->method('rollBack');
        $this->expectException(RuntimeException::class);
        $this->service->record('9', $this->input(), 42);
    }
}
