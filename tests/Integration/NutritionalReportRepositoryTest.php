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

final class NutritionalReportRepositoryTest extends TestCase
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

    public function test_AC10_AC12_summary_matches_manual_cohort_with_period_age_ties_and_archival_boundaries(): void
    {
        $a = $this->child('2089-01-01');
        $this->measure($a, '2090-01-01', 'Underweight');
        $this->measure($a, '2090-01-31');
        $this->measure($a, '2090-02-01', 'Underweight');
        $b = $this->child('2085-02-01', 'F', true);
        $this->measure($b, '2090-01-31'); // 59 completed months: included.
        $this->measure($this->child('2085-01-01'), '2090-01-31'); // 60 months: excluded.
        $this->measure($this->child('2090-02-01'), '2090-01-31'); // Before birth: excluded.
        $this->child('2089-01-01'); // No measurement: excluded.
        $this->measure($this->child('2089-01-01', 'F', false, true), '2090-01-01');
        $g = $this->child('2085-01-15', 'F');
        $this->measure($g, '2090-01-01');
        $this->measure($g, '2090-01-31'); // Latest is 60 months: do not fall back.
        $h = $this->child('2089-01-01', 'M', true);
        $this->measure($h, '2090-01-01');
        $this->measure($h, '2090-01-01', null); // Greater ID wins on the same date.
        $this->measure($this->child('2089-01-01'), '2089-12-31');
        $repo = new ReportRepository($this->pdo);
        $service = new ReportService($repo, new ReportFilterValidator());
        $filters = ['from' => '2090-01-01', 'to' => '2090-01-31'];
        $result = $service->nutritional($filters, 'STAFF');
        self::assertSame(['M' => 2, 'F' => 1, 'total' => 3], $result['groups'][0]['totals']);
        self::assertCount(3, $result['groups']);
        self::assertSame(1, $result['groups'][1]['totals']['total']);
        self::assertSame(2, $result['groups'][2]['totals']['total']);
        foreach ($result['groups'][0]['indicators'] as $categories) {
            self::assertSame(2, $categories['Normal']['total']);
            self::assertSame(1, $categories['Not available']['total']);
            self::assertSame(3, array_sum(array_column($categories, 'total')));
        }
        $included = $service->nutritional($filters + ['include_archived' => '1'], 'STAFF');
        self::assertSame(['M' => 2, 'F' => 2, 'total' => 4], $included['groups'][0]['totals']);
        $viewer = $service->nutritional($filters + ['purok_id' => (string) $this->north, 'byPurok' => '1'], 'VIEWER');
        self::assertCount(1, $viewer['groups']);
        self::assertSame($result['groups'][0], $viewer['groups'][0]);
        foreach ($repo->nutritionalCounts($filters + ['include_archived' => '0'], false) as $row) {
            self::assertArrayNotHasKey('purok_id', $row);
            self::assertArrayNotHasKey('purok_name', $row);
            self::assertArrayNotHasKey('child_id', $row);
            self::assertArrayNotHasKey('date_of_birth', $row);
        }
        $empty = $service->nutritional(['from' => '2091-01-01', 'to' => '2091-01-31'], 'VIEWER');
        self::assertSame(0, $empty['groups'][0]['totals']['total']);
    }
}
