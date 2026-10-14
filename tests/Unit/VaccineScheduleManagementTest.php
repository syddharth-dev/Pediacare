<?php

declare(strict_types=1);

use Pediacare\Controllers\Admin\ScheduleController;
use Pediacare\Controllers\AuthController;
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
use Pediacare\Repositories\LoginAttemptRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Repositories\VaccineScheduleRepository;
use Pediacare\Services\AuthService;
use Pediacare\Services\VaccineScheduleService;
use Pediacare\Support\AuditLogger;
use Pediacare\Validation\VaccineScheduleValidator;
use PHPUnit\Framework\TestCase;

final class VaccineScheduleManagementTest extends TestCase
{
    private UserRepository $users;
    private VaccineScheduleRepository $schedule;
    private PDO $pdo;
    private AuditRepository $audit;
    private Csrf $csrf;
    private Router $router;
    private array $previousSession;

    protected function setUp(): void
    {
        $this->previousSession = $_SESSION ?? [];
        $_SESSION = ['user_id' => 42];
        $this->users = $this->createMock(UserRepository::class);
        $this->schedule = $this->createMock(VaccineScheduleRepository::class);
        $this->pdo = $this->createMock(PDO::class);
        $this->audit = $this->createMock(AuditRepository::class);
        $auth = new AuthService($this->users, $this->createMock(LoginAttemptRepository::class), new Session());
        $this->csrf = new Csrf($_SESSION);
        $flash = new Flash($_SESSION);
        $view = new View(dirname(__DIR__, 2) . '/views');
        $container = new Container();
        $container->register(AuthMiddleware::class, static fn (): AuthMiddleware => new AuthMiddleware($auth));
        $container->register(RoleMiddleware::class, static fn (): RoleMiddleware => new RoleMiddleware($auth));
        $container->register(AuthController::class, fn (): AuthController => new AuthController($auth, $this->csrf, $view));
        $container->register(ScheduleController::class, fn (): ScheduleController => new ScheduleController(
            $this->service(), $auth, $this->csrf, $flash, $view
        ));
        $this->router = new Router($container, require dirname(__DIR__, 2) . '/src/routes.php');
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->previousSession;
    }

    private function service(): VaccineScheduleService
    {
        return new VaccineScheduleService($this->pdo, $this->schedule, new VaccineScheduleValidator(), new AuditLogger($this->audit));
    }

    private function dispatch(string $method, string $path, array $post = []): Response
    {
        return (new CsrfMiddleware($this->csrf))->handle(new Request($method, $path, [], $post),
            fn (Request $request): Response => $this->router->dispatch($request));
    }

    private function admin(): void
    {
        $this->users->method('findById')->willReturn(['id' => 42, 'name' => 'FAKE Admin', 'role' => 'ADMIN', 'active' => 1]);
    }

    private function rows(): void
    {
        $vaccine = ['id' => 7, 'code' => 'FAKE', 'name' => '<FAKE & Vaccine>', 'active' => 1];
        $dose = ['id' => 9, 'vaccine_id' => 7, 'dose_number' => 2, 'recommended_age_days' => null,
            'recommended_age_months' => 9, 'grace_days' => 0, 'min_interval_days' => null,
            'min_interval_months' => 4, 'counts_toward_fic' => 1, 'sort_order' => 2,
            'vaccine_name' => $vaccine['name'], 'vaccine_code' => 'FAKE'];
        $this->schedule->method('all')->willReturn([$vaccine + ['doses' => [$dose]]]);
        $this->schedule->method('vaccine')->willReturn($vaccine);
        $this->schedule->method('dose')->willReturn($dose);
    }

    private function transaction(): void
    {
        $active = false;
        $this->pdo->method('inTransaction')->willReturnCallback(static function () use (&$active): bool {
            return $active;
        });
        $this->pdo->method('beginTransaction')->willReturnCallback(static function () use (&$active): bool {
            $active = true;
            return true;
        });
        $this->pdo->method('commit')->willReturnCallback(static function () use (&$active): bool {
            $active = false;
            return true;
        });
        $this->pdo->method('rollBack')->willReturnCallback(static function () use (&$active): bool {
            $active = false;
            return true;
        });
    }

    private static function doseInput(): array
    {
        return ['dose_number' => '2', 'age_value' => '9', 'age_unit' => 'months', 'grace_days' => '7',
            'interval_value' => '4', 'interval_unit' => 'months', 'counts_toward_fic' => '1', 'sort_order' => '2'];
    }

    private static function paths(): array
    {
        return [['GET', '/admin/schedule'], ['GET', '/admin/schedule/vaccines/7'], ['GET', '/admin/schedule/doses/9'],
            ['POST', '/admin/schedule/vaccines'], ['POST', '/admin/schedule/vaccines/7'],
            ['POST', '/admin/schedule/vaccines/7/doses'], ['POST', '/admin/schedule/doses/9']];
    }

    public function test_AC01_all_schedule_routes_require_login(): void
    {
        $this->users->method('findById')->willReturn(null);
        $this->schedule->expects(self::never())->method('all');
        foreach (self::paths() as [$method, $path]) {
            $response = $this->dispatch($method, $path, ['_csrf_token' => $this->csrf->token()]);
            self::assertSame(303, $response->status);
            self::assertSame('/login', $response->headers['Location']);
        }
    }

    public function test_AC02_schedule_navigation_and_all_routes_are_admin_only(): void
    {
        $role = 'BHW';
        $this->users->method('findById')->willReturnCallback(static function () use (&$role): array {
            return ['id' => 42, 'name' => 'FAKE Staff', 'role' => $role, 'active' => 1];
        });
        foreach (['all', 'vaccine', 'dose', 'insertVaccine', 'updateVaccine', 'insertDose', 'updateDose'] as $method) {
            $this->schedule->expects(self::never())->method($method);
        }
        foreach (['BHW', 'STAFF', 'VIEWER'] as $role) {
            self::assertStringNotContainsString('/admin/schedule', $this->dispatch('GET', '/login')->body);
            foreach (self::paths() as [$method, $path]) {
                self::assertSame(403, $this->dispatch($method, $path, ['_csrf_token' => $this->csrf->token()])->status);
            }
        }
    }

    public function test_AC02_all_schedule_writes_require_csrf_before_any_transaction(): void
    {
        $this->admin();
        $this->pdo->expects(self::never())->method('beginTransaction');
        foreach (self::paths() as [$method, $path]) {
            if ($method === 'POST') {
                self::assertSame(403, $this->dispatch($method, $path, self::doseInput())->status);
            }
        }
    }

    public function test_AC02_admin_forms_escape_values_label_controls_and_preserve_calendar_units(): void
    {
        $this->admin();
        $this->rows();
        self::assertStringContainsString('/admin/schedule', $this->dispatch('GET', '/login')->body);
        foreach (['/admin/schedule', '/admin/schedule/vaccines/7', '/admin/schedule/doses/9'] as $path) {
            $response = $this->dispatch('GET', $path);
            self::assertSame(200, $response->status);
            self::assertSame('no-store', $response->headers['Cache-Control']);
            self::assertStringContainsString('&lt;FAKE &amp; Vaccine&gt;', $response->body);
            self::assertStringNotContainsString('<FAKE & Vaccine>', $response->body);
            $document = new DOMDocument();
            @$document->loadHTML($response->body);
            $xpath = new DOMXPath($document);
            self::assertSame($xpath->query('//form')->length, $xpath->query('//form/input[@name="_csrf_token"]')->length);
            foreach ($xpath->query('//form//input[not(@type="hidden")] | //form//select') as $input) {
                self::assertSame(1, $xpath->query('//label[@for="' . $input->getAttribute('id') . '"]')->length);
            }
            if ($path === '/admin/schedule/doses/9') {
                self::assertSame(2, $xpath->query('//option[@value="months" and @selected]')->length);
            }
        }
    }

    public function test_AC02_invalid_inputs_show_escaped_errors_without_writing(): void
    {
        $this->admin();
        $this->rows();
        $this->pdo->expects(self::never())->method('beginTransaction');
        foreach ([['/admin/schedule/vaccines', ['code' => 'FAKE', 'name' => '<FAKE Name>', 'active' => []]],
            ['/admin/schedule/vaccines/7', ['name' => '<FAKE Name>', 'active' => '2']],
            ['/admin/schedule/vaccines/7/doses', array_replace(self::doseInput(), ['age_value' => '-1'])],
            ['/admin/schedule/doses/9', array_replace(self::doseInput(), ['interval_unit' => []])]] as [$path, $input]) {
            $response = $this->dispatch('POST', $path, $input + ['_csrf_token' => $this->csrf->token()]);
            self::assertSame(422, $response->status);
            self::assertStringContainsString('id="schedule-error"', $response->body);
            self::assertStringNotContainsString('<FAKE Name>', $response->body);
        }
    }

    public function test_AC02_all_four_writes_redirect_audit_authenticated_actor_and_ignore_injected_identity(): void
    {
        $this->admin();
        $this->rows();
        $this->transaction();
        $this->schedule->expects(self::once())->method('insertVaccine')->with(['code' => 'FAKE', 'name' => 'FAKE New', 'active' => 1])->willReturn(8);
        $this->schedule->expects(self::once())->method('updateVaccine')->with(7, ['name' => 'FAKE Changed', 'active' => 0]);
        $data = (new VaccineScheduleValidator())->dose(self::doseInput());
        $this->schedule->expects(self::once())->method('insertDose')->with($data + ['vaccine_id' => 7])->willReturn(10);
        $this->schedule->expects(self::once())->method('updateDose')->with(9, $data);
        $this->audit->expects(self::exactly(4))->method('insert')->willReturnCallback(function (int $actor): void {
            self::assertSame(42, $actor);
            self::assertTrue($this->pdo->inTransaction());
        });
        $this->pdo->expects(self::exactly(4))->method('commit');
        foreach ([['/admin/schedule/vaccines', ['code' => 'FAKE', 'name' => ' FAKE New ', 'active' => '1']],
            ['/admin/schedule/vaccines/7', ['code' => 'INJECTED', 'name' => 'FAKE Changed', 'active' => '0']],
            ['/admin/schedule/vaccines/7/doses', self::doseInput()], ['/admin/schedule/doses/9', self::doseInput()]] as [$path, $input]) {
            self::assertSame(303, $this->dispatch('POST', $path, $input + ['_csrf_token' => $this->csrf->token(),
                'actor' => '666', 'vaccine_id' => '666'])->status);
        }
        self::assertStringContainsString('Dose settings saved.', $this->dispatch('GET', '/admin/schedule')->body);
    }

    public function test_AC02_used_dose_cannot_be_renumbered_and_keeps_entered_values(): void
    {
        $this->admin();
        $this->rows();
        $this->transaction();
        $this->schedule->method('doseUsed')->with(9)->willReturn(true);
        $this->schedule->expects(self::never())->method('updateDose');
        $this->audit->expects(self::never())->method('insert');
        $this->pdo->expects(self::once())->method('rollBack');
        $response = $this->dispatch('POST', '/admin/schedule/doses/9', array_replace(self::doseInput(),
            ['dose_number' => '3', '_csrf_token' => $this->csrf->token()]));
        self::assertSame(422, $response->status);
        self::assertStringContainsString('already has vaccination records', $response->body);
        self::assertStringContainsString('value="3"', $response->body);
    }

    public function test_AC02_missing_or_malformed_record_urls_return_404(): void
    {
        $this->admin();
        $this->transaction();
        foreach (['0', '7', '4294967296', 'invalid'] as $id) {
            foreach (['vaccines', 'doses'] as $type) {
                self::assertSame(404, $this->dispatch('GET', '/admin/schedule/' . $type . '/' . $id)->status);
                $input = $type === 'vaccines' ? ['name' => 'FAKE', 'active' => '1'] : self::doseInput();
                self::assertSame(404, $this->dispatch('POST', '/admin/schedule/' . $type . '/' . $id,
                    $input + ['_csrf_token' => $this->csrf->token()])->status);
            }
        }
    }

    public function test_AC02_audit_failure_rolls_back_schedule_changes(): void
    {
        $this->transaction();
        $this->schedule->expects(self::once())->method('insertVaccine')->willReturn(8);
        $this->audit->method('insert')->willThrowException(new RuntimeException('FAKE audit failure'));
        $this->pdo->expects(self::never())->method('commit');
        $this->pdo->expects(self::once())->method('rollBack');
        $this->expectException(RuntimeException::class);
        $this->service()->saveVaccine(null, ['code' => 'FAKE', 'name' => 'FAKE', 'active' => '1'], 42);
    }

    public function test_AC02_duplicate_dose_is_a_safe_validation_error(): void
    {
        $this->transaction();
        $this->rows();
        $error = new PDOException('FAKE private SQL');
        $error->errorInfo = ['23000', 1062, 'FAKE private SQL'];
        $this->schedule->method('insertDose')->willThrowException($error);
        $this->pdo->expects(self::once())->method('rollBack');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');
        $this->service()->saveDose(null, '7', self::doseInput(), 42);
    }

    public function test_AC02_empty_schedule_has_setup_guidance(): void
    {
        $this->admin();
        self::assertStringContainsString('No vaccines yet.', $this->dispatch('GET', '/admin/schedule')->body);
    }
}
