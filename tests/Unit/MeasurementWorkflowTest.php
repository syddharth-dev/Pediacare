<?php

declare(strict_types=1);

use Pediacare\Controllers\MeasurementController;
use Pediacare\Core\Container;
use Pediacare\Core\Csrf;
use Pediacare\Core\Flash;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Core\Router;
use Pediacare\Core\Session;
use Pediacare\Core\View;
use Pediacare\Domain\Growth\LmsTable;
use Pediacare\Middleware\AuthMiddleware;
use Pediacare\Middleware\RoleMiddleware;
use Pediacare\Middleware\CsrfMiddleware;
use Pediacare\Repositories\AuditRepository;
use Pediacare\Repositories\ChildRepository;
use Pediacare\Repositories\MeasurementRepository;
use Pediacare\Repositories\WhoLmsRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Repositories\RecordCounterRepository;
use Pediacare\Services\AuthService;
use Pediacare\Services\ChildService;
use Pediacare\Services\MeasurementService;
use Pediacare\Support\AuditLogger;
use Pediacare\Support\MeasurementConfirmation;
use Pediacare\Support\RecordNumberGenerator;
use Pediacare\Validation\MeasurementValidator;
use Pediacare\Validation\ChildValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MeasurementWorkflowTest extends TestCase
{
    private static ?LmsTable $table = null;
    private array $previousSession;
    private array $child;
    private ?string $role = 'STAFF';
    private bool $active = false;
    private PDO $pdo;
    private ChildRepository $children;
    private MeasurementRepository $measurements;
    private AuditRepository $audit;
    private MeasurementService $service;
    private Router $router;
    private Csrf $csrf;

    protected function setUp(): void
    {
        $this->previousSession = $_SESSION ?? [];
        $_SESSION = ['user_id' => 42];
        $this->child = ['id' => 9, 'date_of_birth' => '2000-01-01', 'sex' => 'M', 'archived_at' => null,
            'full_name' => '<FAKE Child>', 'record_no' => 'PC-2000-00009'];
        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('inTransaction')->willReturnCallback(fn (): bool => $this->active);
        $this->pdo->method('beginTransaction')->willReturnCallback(function (): bool { $this->active = true; return true; });
        $this->pdo->method('commit')->willReturnCallback(function (): bool { $this->active = false; return true; });
        $this->pdo->method('rollBack')->willReturnCallback(function (): bool { $this->active = false; return true; });
        $this->children = $this->createMock(ChildRepository::class);
        $this->children->method('lockById')->willReturnCallback(fn (): ?array => $this->child === [] ? null : $this->child);
        $this->children->method('findById')->willReturnCallback(fn (): ?array => $this->child === [] ? null : $this->child);
        $this->measurements = $this->createMock(MeasurementRepository::class);
        $this->audit = $this->createMock(AuditRepository::class);
        $references = $this->createMock(WhoLmsRepository::class);
        self::$table ??= require dirname(__DIR__) . '/Fixtures/who-lms.php';
        $references->method('load')->willReturn(self::$table);
        $this->service = new MeasurementService($this->pdo, $this->children, $this->measurements, $references,
            new MeasurementValidator(), new AuditLogger($this->audit), new MeasurementConfirmation($_SESSION));
        $children = new ChildService($this->pdo, $this->children, $this->createMock(PurokRepository::class),
            new ChildValidator(), new RecordNumberGenerator($this->createMock(RecordCounterRepository::class)), new AuditLogger($this->audit));
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturnCallback(fn (): ?array => $this->role === null ? null
            : ['id' => 42, 'name' => 'FAKE Staff', 'role' => $this->role, 'active' => 1]);
        $auth = new AuthService($users, $this->createMock(LoginAttemptRepository::class), new Session());
        $this->csrf = new Csrf($_SESSION);
        $container = new Container();
        $container->register(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($auth));
        $container->register(RoleMiddleware::class, static fn (): RoleMiddleware => new RoleMiddleware($auth));
        $container->register(MeasurementController::class, fn (): MeasurementController => new MeasurementController(
            $this->service, $children, $auth, $this->csrf, new Flash($_SESSION), new View(dirname(__DIR__, 2) . '/views')));
        $this->router = new Router($container, require dirname(__DIR__, 2) . '/src/routes.php');
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->previousSession;
    }

    private function input(bool $extreme = false): array
    {
        return ['measured_at' => '2000-12-31', 'weight_kg' => $extreme ? '20' : '9.5',
            'height_cm' => '75.4', 'muac_cm' => '', 'position' => 'LYING'];
    }

    private function dispatch(string $method, array $post = [], string $id = '9'): Response
    {
        $path = '/children/' . $id . '/measurements' . ($method === 'GET' ? '/new' : '');

        return (new CsrfMiddleware($this->csrf))->handle(new Request($method, $path, [], $post),
            fn (Request $request): Response => $this->router->dispatch($request));
    }

    public static function writers(): iterable
    {
        yield ['ADMIN'];
        yield ['STAFF'];
        yield ['BHW'];
    }

    #[DataProvider('writers')]
    public function test_AC06_authorized_roles_can_open_accessible_escaped_form(string $role): void
    {
        $this->role = $role;
        $response = $this->dispatch('GET');
        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertStringContainsString('&lt;FAKE Child&gt;', $response->body);
        self::assertStringNotContainsString('<FAKE Child>', $response->body);
        $dom = new DOMDocument();
        @$dom->loadHTML($response->body);
        $xpath = new DOMXPath($dom);
        self::assertSame(1, $xpath->query('//form[@method="post"]//input[@name="_csrf_token"]')->length);
        foreach (['measured_at', 'weight_kg', 'height_cm', 'muac_cm', 'position'] as $name) {
            $field = $xpath->query('//*[@name="' . $name . '"]')->item(0);
            self::assertNotNull($field);
            self::assertSame(1, $xpath->query('//label[@for="' . $field->getAttribute('id') . '"]')->length);
        }
    }

    public function test_AC01_logged_out_routes_redirect_before_child_queries(): void
    {
        $this->role = null;
        $this->children->expects(self::never())->method('findById');
        $this->children->expects(self::never())->method('lockById');
        self::assertSame('/login', $this->dispatch('GET')->headers['Location']);
        self::assertSame('/login', $this->dispatch('POST', ['_csrf_token' => $this->csrf->token()])->headers['Location']);
    }

    public function test_AC03_viewer_cannot_read_form_or_post_measurements(): void
    {
        $this->role = 'VIEWER';
        $this->children->expects(self::never())->method('findById');
        $this->children->expects(self::never())->method('lockById');
        self::assertSame(403, $this->dispatch('GET')->status);
        self::assertSame(403, $this->dispatch('POST', ['_csrf_token' => $this->csrf->token()])->status);
    }

    public function test_AC11_POST_requires_CSRF_before_any_write(): void
    {
        $this->pdo->expects(self::never())->method('beginTransaction');
        self::assertSame(403, $this->dispatch('POST', $this->input())->status);
    }

    public function test_AC06_AC11_server_computes_results_and_audit_inside_the_same_transaction(): void
    {
        $this->measurements->expects(self::once())->method('insert')->willReturnCallback(function (array $data): int {
            self::assertTrue($this->active);
            self::assertSame('16.71', $data['bmi']);
            self::assertSame('-0.14', $data['wfa_z']);
            self::assertSame('Normal', $data['wfa_status']);
            self::assertSame(42, $data['measured_by']);
            self::assertSame(0, $data['implausible_confirmed']);
            self::assertArrayNotHasKey('confirmation_token', $data);
            return 12;
        });
        $this->audit->expects(self::once())->method('insert')->willReturnCallback(function (int $actor, string $action, string $entity, int $id, string $json): void {
            self::assertTrue($this->active);
            self::assertSame([42, 'CREATE', 'measurements', 12], [$actor, $action, $entity, $id]);
            self::assertSame('16.71', json_decode($json, true, 512, JSON_THROW_ON_ERROR)['after']['bmi']);
        });
        $post = $this->input() + ['_csrf_token' => $this->csrf->token(), 'bmi' => '1', 'measured_by' => '999'];
        $response = $this->dispatch('POST', $post);
        self::assertSame(303, $response->status);
        self::assertSame('/children/9?tab=growth', $response->headers['Location']);
        self::assertFalse($this->active);
    }

    public function test_AC14_warning_then_matching_explicit_confirmation_saves_and_consumes_token(): void
    {
        $this->measurements->expects(self::once())->method('insert')->willReturnCallback(function (array $data): int {
            self::assertSame(1, $data['implausible_confirmed']);
            return 12;
        });
        $first = $this->service->record('9', $this->input(true), 42);
        self::assertNull($first->id);
        self::assertContains('wfa_z', $first->flags);
        self::assertFalse($this->active);
        $saved = $this->service->record('9', $this->input(true) + ['confirm_implausible' => '1', 'confirmation_token' => $first->confirmationToken], 42);
        self::assertSame(12, $saved->id);
        self::assertArrayNotHasKey('_measurement_confirmation', $_SESSION);
        self::assertNull($this->service->record('9', $this->input(true) + ['confirm_implausible' => '1', 'confirmation_token' => $first->confirmationToken], 42)->id);
    }

    public static function tampering(): iterable
    {
        yield ['weight_kg', '21'];
        yield ['height_cm', '76.0'];
        yield ['measured_at', '2001-01-01'];
        yield ['muac_cm', '12.0'];
        yield ['position', 'STANDING'];
        yield ['date_of_birth', '2000-01-02'];
        yield ['sex', 'F'];
        yield ['child_id', '10'];
        yield ['actor', '43'];
    }

    #[DataProvider('tampering')]
    public function test_AC14_confirmation_cannot_be_reused_after_values_demographics_child_or_actor_change(string $field, string $value): void
    {
        $this->measurements->expects(self::never())->method('insert');
        $this->audit->expects(self::never())->method('insert');
        $first = $this->service->record('9', $this->input(true), 42);
        $post = $this->input(true) + ['confirm_implausible' => '1', 'confirmation_token' => $first->confirmationToken];
        if (in_array($field, ['date_of_birth', 'sex'], true)) {
            $this->child[$field] = $value;
        } else {
            $post[$field] = $value;
        }
        $result = $this->service->record($field === 'child_id' ? $value : '9', $post, $field === 'actor' ? (int) $value : 42);
        self::assertNull($result->id);
        self::assertNotSame($first->confirmationToken, $result->confirmationToken);
    }

    public function test_AC14_forged_confirmation_flag_does_not_bypass_warning_and_form_preserves_values(): void
    {
        $this->measurements->expects(self::never())->method('insert');
        $post = $this->input(true) + ['_csrf_token' => $this->csrf->token(), 'confirm_implausible' => '1', 'confirmation_token' => 'FAKE_TOKEN'];
        $response = $this->dispatch('POST', $post);
        self::assertSame(409, $response->status);
        self::assertStringContainsString('name="confirmation_token"', $response->body);
        self::assertStringContainsString('value="20"', $response->body);
        self::assertStringContainsString('Check these measurement values', $response->body);
        $dom = new DOMDocument();
        @$dom->loadHTML($response->body);
        self::assertSame(0, (new DOMXPath($dom))->query('//input[@name="confirm_implausible"][@checked]')->length);
    }

    public function test_AC11_audit_failure_rolls_back_without_committing(): void
    {
        $this->measurements->method('insert')->willReturn(12);
        $this->audit->method('insert')->willThrowException(new RuntimeException('FAKE audit failure'));
        $this->pdo->expects(self::once())->method('rollBack');
        $this->pdo->expects(self::never())->method('commit');
        $this->expectException(RuntimeException::class);
        $this->service->record('9', $this->input(), 42);
    }

    public function test_AC06_missing_and_archived_children_are_rejected(): void
    {
        $this->child = [];
        self::assertSame(404, $this->dispatch('GET')->status);
        self::assertSame(404, $this->dispatch('POST', $this->input() + ['_csrf_token' => $this->csrf->token()])->status);
        $this->child = ['archived_at' => '2001-01-01'];
        self::assertSame(409, $this->dispatch('GET')->status);
        self::assertSame(409, $this->dispatch('POST', $this->input() + ['_csrf_token' => $this->csrf->token()])->status);
    }

    public function test_AC06_invalid_input_is_escaped_preserved_and_not_saved(): void
    {
        $this->measurements->expects(self::never())->method('insert');
        $response = $this->dispatch('POST', array_replace($this->input(), ['weight_kg' => '<FAKE>', '_csrf_token' => $this->csrf->token()]));
        self::assertSame(422, $response->status);
        self::assertStringContainsString('&lt;FAKE&gt;', $response->body);
        self::assertStringContainsString('role="alert"', $response->body);
    }

    public function test_AC06_measurement_before_birth_and_unsupported_age_do_not_write(): void
    {
        $this->measurements->expects(self::never())->method('insert');
        $this->audit->expects(self::never())->method('insert');
        foreach (['1999-12-31', '2026-01-01'] as $date) {
            $response = $this->dispatch('POST', array_replace($this->input(), ['measured_at' => $date, '_csrf_token' => $this->csrf->token()]));
            self::assertSame(422, $response->status);
            self::assertFalse($this->active);
        }
    }

    public function test_AC11_service_refuses_an_existing_transaction_without_rolling_it_back(): void
    {
        $this->active = true;
        $this->pdo->expects(self::never())->method('rollBack');
        $this->children->expects(self::never())->method('lockById');
        $this->expectException(LogicException::class);
        $this->service->record('9', $this->input(), 42);
    }

    public function test_AC06_missing_required_reference_data_is_not_silently_saved(): void
    {
        $references = $this->createMock(WhoLmsRepository::class);
        $references->method('load')->willReturn(new LmsTable([]));
        $this->measurements->expects(self::never())->method('insert');
        $this->pdo->expects(self::once())->method('rollBack');
        $service = new MeasurementService($this->pdo, $this->children, $this->measurements, $references,
            new MeasurementValidator(), new AuditLogger($this->audit), new MeasurementConfirmation($_SESSION));
        $this->expectException(LogicException::class);
        $service->record('9', $this->input(), 42);
    }

    public function test_AC06_calculated_values_outside_database_precision_fail_without_saving(): void
    {
        $this->measurements->expects(self::never())->method('insert');
        $response = $this->dispatch('POST', array_replace($this->input(), ['height_cm' => '0.1', '_csrf_token' => $this->csrf->token()]));
        self::assertSame(422, $response->status);
        self::assertFalse($this->active);
    }
}
