<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Repositories\ReportRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\MigrationService;
use Pediacare\Services\ReportService;
use Pediacare\Validation\ReportFilterValidator;
use PHPUnit\Framework\TestCase;

final class MonthlyImmunizationReportRepositoryTest extends TestCase
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

    private function dose(int $child, int $dose, string $date, int $elsewhere = 0): void
    {
        (new \Pediacare\Repositories\ImmunizationRepository($this->pdo))->insert([
            'child_id' => $child, 'schedule_dose_id' => $dose, 'date_given' => $date,
            'lot_number' => null, 'site' => null, 'given_elsewhere' => $elsewhere,
            'place_given' => 'FAKE PLACE', 'given_by' => $this->actor]);
    }

    public function test_AC08_AC12_monthly_counts_match_manual_dataset_and_current_fic_configuration(): void
    {
        $this->pdo->prepare('UPDATE schedule_doses SET counts_toward_fic = 0')->execute();
        $vaccine = $this->pdo->prepare('INSERT INTO vaccines (code, name, active) VALUES (:code, :name, :active)');
        $vaccine->execute(['code' => 'FAKE_' . bin2hex(random_bytes(6)), 'name' => 'FAKE MONTHLY VACCINE', 'active' => 0]);
        $id = (int) $this->pdo->lastInsertId();
        $dose = $this->pdo->prepare('INSERT INTO schedule_doses (vaccine_id, dose_number, recommended_age_days,
            grace_days, min_interval_days, counts_toward_fic, sort_order) VALUES (:vaccine, :number, 0, 0, 0, :fic, :sort)');
        $ids = [];
        foreach ([1, 2, 3] as $number) {
            $dose->execute(['vaccine' => $id, 'number' => $number, 'fic' => $number < 3 ? 1 : 0, 'sort' => $number]);
            $ids[] = (int) $this->pdo->lastInsertId();
        }
        $a = $this->child('2080-01-01'); // No invented under-five FIC restriction.
        $this->dose($a, $ids[0], '2090-01-31');
        $this->dose($a, $ids[1], '2090-02-01', 1);
        $this->dose($a, $ids[2], '2090-03-01'); // Non-FIC dose does not move completion date.
        $b = $this->child('2089-01-01', 'F');
        $this->dose($b, $ids[0], '2090-02-28');
        $this->dose($b, $ids[1], '2090-02-28');
        $c = $this->child('2089-01-01');
        $this->dose($c, $ids[0], '2090-01-01');
        $this->dose($c, $ids[1], '2090-01-31'); // Previous completion excluded.
        $d = $this->child('2089-01-01');
        $this->dose($d, $ids[0], '2090-02-01');
        $this->dose($d, $ids[1], '2090-03-01'); // Next-month completion excluded.
        $e = $this->child('2089-01-01', 'F');
        $this->dose($e, $ids[0], '2090-02-10'); // Incomplete series excluded from FIC.
        $f = $this->child('2089-01-01', 'F', false, true);
        $this->dose($f, $ids[0], '2090-01-01');
        $this->dose($f, $ids[1], '2090-02-28');
        $service = new ReportService(new ReportRepository($this->pdo), new ReportFilterValidator());
        $result = $service->immunization(['month' => '2090-02', 'purok_id' => '99999'], 'VIEWER');
        self::assertSame(['M' => 2, 'F' => 3, 'total' => 5], $result['totals']);
        self::assertSame(['M' => 1, 'F' => 1, 'total' => 2], $result['fic']);
        $rows = array_values(array_filter($result['vaccines'], static fn (array $v): bool => $v['name'] === 'FAKE MONTHLY VACCINE'));
        self::assertSame($result['totals'], $rows[0]['counts']);
        self::assertArrayNotHasKey('child_id', $result);
        self::assertArrayNotHasKey('purok_id', $result);
        $included = $service->immunization(['month' => '2090-02', 'include_archived' => '1'], 'ADMIN');
        self::assertSame(['M' => 2, 'F' => 4, 'total' => 6], $included['totals']);
        self::assertSame(['M' => 1, 'F' => 2, 'total' => 3], $included['fic']);
        $this->pdo->prepare('UPDATE schedule_doses SET counts_toward_fic = 0')->execute();
        self::assertSame(0, $service->immunization(['month' => '2090-02'], 'STAFF')['fic']['total']);
        $this->pdo->prepare('UPDATE schedule_doses SET counts_toward_fic = 1 WHERE id = :id')->execute(['id' => $ids[2]]);
        self::assertSame(0, $service->immunization(['month' => '2090-02'], 'STAFF')['fic']['total']);
        self::assertSame(1, $service->immunization(['month' => '2090-03'], 'STAFF')['fic']['total']);
        $empty = $service->immunization(['month' => '2091-01'], 'VIEWER');
        self::assertSame(0, $empty['totals']['total']);
        self::assertSame(0, $empty['fic']['total']);
    }
}
