<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Repositories\MeasurementRepository;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Repositories\ReportRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\MigrationService;
use Pediacare\Services\ReportService;
use Pediacare\Validation\ReportFilterValidator;
use PHPUnit\Framework\TestCase;

final class MalnutritionReportRepositoryTest extends TestCase
{
    private PDO $pdo;
    private int $actor;
    private int $north;
    private int $south;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $path = getenv('PEDIACARE_TEST_CONFIG') ?: dirname(__DIR__, 2) . '/config/config.local.php';
        if (!is_file($path)) {
            self::markTestSkipped('Configure pediacare_test to run MySQL integration tests.');
        }
        $config = (require $path)['database'];
        self::assertSame('pediacare_test', $config['test_name']);
        self::assertNotSame($config['name'], $config['test_name']);
        $config['name'] = $config['test_name'];
        $this->pdo = Database::connect($config);
        (new MigrationService(new MigrationRepository($this->pdo)))->run(dirname(__DIR__, 2) . '/database/migrations');
        $this->pdo->beginTransaction();
        $prefix = 'FAKE' . bin2hex(random_bytes(8));
        $users = new UserRepository($this->pdo);
        $users->insertAdmin('FAKE Nutrition Staff', $prefix . '@example.invalid', 'FAKE_UNUSED_HASH');
        $this->actor = (int) $users->findByEmail($prefix . '@example.invalid')['id'];
        $puroks = new PurokRepository($this->pdo);
        $puroks->insert($prefix . ' North');
        $this->north = (int) $this->pdo->lastInsertId();
        $puroks->insert($prefix . ' South');
        $this->south = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function child(string $birth, string $sex = 'M', bool $south = false, bool $archived = false): int
    {
        $query = $this->pdo->prepare('INSERT INTO children (record_no, first_name, last_name, sex, date_of_birth,
            place_of_birth, purok_id, address, mother_name, guardian_name, guardian_relation, contact_no, created_by, archived_at)
            VALUES (:number, :first, :last, :sex, :birth, :place, :purok, :address, :mother, :guardian, :relation, :contact, :actor, :archived)');
        $query->execute(['number' => 'PC-2090-' . str_pad((string) ++$this->sequence, 5, '0', STR_PAD_LEFT),
            'first' => 'FAKE REPORT CHILD', 'last' => 'FAKE FAMILY', 'sex' => $sex, 'birth' => $birth,
            'place' => 'FAKE PLACE', 'purok' => $south ? $this->south : $this->north,
            'address' => 'FAKE ADDRESS', 'mother' => 'FAKE MOTHER', 'guardian' => 'FAKE GUARDIAN',
            'relation' => 'FAKE RELATION', 'contact' => 'FAKE CONTACT', 'actor' => $this->actor,
            'archived' => $archived ? '2090-01-01 00:00:00' : null]);

        return (int) $this->pdo->lastInsertId();
    }

    private function measure(int $child, string $date, ?string $status = 'Normal'): void
    {
        // These saved fake labels test report selection and counting, not clinical computation.
        (new MeasurementRepository($this->pdo))->insert(['child_id' => $child, 'measured_at' => $date,
            'weight_kg' => '6.00', 'height_cm' => '60.0', 'position' => 'LYING', 'muac_cm' => null,
            'age_months' => 99, 'bmi' => '16.67', 'wfa_z' => null, 'hfa_z' => null, 'wfh_z' => null, 'bmi_z' => null,
            'wfa_status' => $status, 'hfa_status' => $status, 'wfh_status' => $status, 'bmi_status' => $status,
            'implausible_confirmed' => 0, 'measured_by' => $this->actor]);
    }

    public function test_AC12_latest_measurement_selection_and_list_counts_match_manual_dataset(): void
    {
        $expected = [];
        $latest = $this->child('2089-01-01');
        $this->measure($latest, '2090-01-01', 'Underweight');
        $this->measure($latest, '2090-02-01'); // Recovery removes a previous abnormal measurement.
        $sameDate = $this->child('2089-01-01');
        $this->measure($sameDate, '2090-01-01', 'Wasted');
        $this->measure($sameDate, '2090-01-01', null); // Latest ID is unavailable: no fallback.
        $this->child('2089-01-01'); // No measurement.
        $older = $this->child('2080-01-01');
        $this->measure($older, '2090-01-01', 'Thinness');
        $expected[] = $older;
        foreach (['Underweight', 'Severely underweight', 'Stunted', 'Severely stunted', 'Wasted',
            'Severely wasted', 'Overweight', 'Obese', 'High weight-for-age', 'Tall', 'Severe thinness'] as $label) {
            $child = $this->child('2089-01-01');
            $this->measure($child, '2090-01-01', $label);
            $expected[] = $child;
        }
        // Every indicator can independently qualify; unavailable and Normal indicators are retained for display.
        foreach (['wfa', 'hfa', 'wfh', 'bmi'] as $indicator) {
            $child = $this->child('2089-01-01');
            $this->measure($child, '2090-01-01', null);
            $this->pdo->prepare('UPDATE measurements SET ' . $indicator . '_status = :label WHERE child_id = :child')
                ->execute(['label' => 'Underweight', 'child' => $child]);
            $expected[] = $child;
        }
        while (count($expected) < 27) {
            $child = $this->child('2089-01-01');
            $this->measure($child, '2090-01-01', 'Underweight');
            $expected[] = $child;
        }
        $archived = $this->child('2089-01-01', 'F', false, true);
        $this->measure($archived, '2090-01-01', 'Underweight');
        $service = new ReportService(new ReportRepository($this->pdo), new ReportFilterValidator());
        $first = $service->malnutrition([], 'STAFF');
        self::assertSame(27, $first['total']);
        self::assertCount(25, $first['children']);
        self::assertSame(2, $first['pages']);
        $last = $service->malnutrition(['page' => '999'], 'BHW');
        self::assertSame(2, $last['page']);
        self::assertSame(26, $last['from']);
        self::assertSame(27, $last['to']);
        $actual = array_column([...$first['children'], ...$last['children']], 'id');
        self::assertSame($expected, $actual);
        $all = $service->malnutrition(['page' => '2'], 'STAFF', true);
        self::assertSame($expected, array_column($all['children'], 'id'));
        $csv = \Pediacare\Support\ReportOutput::csv('malnutrition', $all);
        self::assertSame(28, count(explode("\r\n", rtrim($csv->body))));
        foreach ($first['children'] as $child) {
            self::assertArrayNotHasKey('address', $child);
            self::assertArrayNotHasKey('date_of_birth', $child);
        }
        $included = $service->malnutrition(['include_archived' => '1', 'page' => '2'], 'ADMIN');
        self::assertSame(28, $included['total']);
        self::assertContains($archived, array_column($included['children'], 'id'));
    }
}
