<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Repositories\AuditRepository;
use Pediacare\Repositories\ChildRepository;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Repositories\RecordCounterRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\ChildService;
use Pediacare\Services\MigrationService;
use Pediacare\Support\AuditLogger;
use Pediacare\Support\RecordNumberGenerator;
use Pediacare\Validation\ChildValidator;
use Pediacare\Validation\ChildFilterValidator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ChildRegistrationRepositoryTest extends TestCase
{
    private PDO $pdo;
    private int $userId;
    private int $purokId;
    private array $input;
    private int $year;
    private ?int $previousNumber;

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
        $users = new UserRepository($this->pdo);
        $email = 'fake-registration-' . bin2hex(random_bytes(8)) . '@example.invalid';
        $users->insertAdmin('FAKE Registration Actor', $email, 'FAKE_UNUSED_HASH');
        $this->userId = (int) $users->findByEmail($email)['id'];
        (new PurokRepository($this->pdo))->insert('FAKE Registration Purok ' . bin2hex(random_bytes(8)));
        $this->purokId = (int) $this->pdo->lastInsertId();
        $this->input = require dirname(__DIR__) . '/Fixtures/child.php';
        $this->input['last_name'] = 'FAKE Family ' . bin2hex(random_bytes(8));
        $this->input['purok_id'] = (string) $this->purokId;
        $this->year = (int) date('Y');
        $this->previousNumber = $this->counter();
    }

    private function counter(): ?int
    {
        $statement = $this->pdo->prepare('SELECT last_number FROM record_counters WHERE year = :year');
        $statement->execute(['year' => $this->year]);
        $number = $statement->fetchColumn();

        return $number === false ? null : (int) $number;
    }

    private function rows(string $table): array
    {
        $sql = match ($table) {
            'children' => 'SELECT * FROM children WHERE created_by = :id ORDER BY id',
            'audit' => 'SELECT * FROM audit_logs WHERE user_id = :id ORDER BY id',
        };
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $this->userId]);

        return $statement->fetchAll();
    }

    private function service(?AuditRepository $audit = null): ChildService
    {
        return new ChildService($this->pdo, new ChildRepository($this->pdo), new PurokRepository($this->pdo),
            new ChildValidator(), new RecordNumberGenerator(new RecordCounterRepository($this->pdo)),
            new AuditLogger($audit ?? new AuditRepository($this->pdo), '2001:db8::1'));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->userId)) {
            foreach (['DELETE FROM audit_logs WHERE user_id = :id', 'DELETE FROM children WHERE created_by = :id',
                'DELETE FROM users WHERE id = :id'] as $sql) {
                $statement = $this->pdo->prepare($sql);
                $statement->execute(['id' => $this->userId]);
            }
        }
        if (isset($this->purokId)) {
            $statement = $this->pdo->prepare('DELETE FROM puroks WHERE id = :id');
            $statement->execute(['id' => $this->purokId]);
        }
        if (isset($this->year)) {
            $statement = $this->pdo->prepare($this->previousNumber === null
                ? 'DELETE FROM record_counters WHERE year = :year'
                : 'UPDATE record_counters SET last_number = :number WHERE year = :year');
            $statement->execute($this->previousNumber === null ? ['year' => $this->year]
                : ['number' => $this->previousNumber, 'year' => $this->year]);
        }
    }

    public function test_AC11_registration_commits_demographics_record_number_and_audit_together(): void
    {
        $input = $this->input;
        $input['first_name'] = "FAKE 'quoted <child>";
        $input['birth_weight_kg'] = '3.5';
        $input['birth_length_cm'] = '50';
        $input['created_by'] = '4294967295';
        $result = $this->service()->register($input, $this->userId);
        $children = $this->rows('children');
        $logs = $this->rows('audit');
        self::assertCount(1, $children);
        self::assertCount(1, $logs);
        self::assertSame($result->recordNumber, $children[0]['record_no']);
        self::assertSame((int) $children[0]['id'], $result->childId);
        self::assertSame($input['first_name'], $children[0]['first_name']);
        self::assertSame('3.50', $children[0]['birth_weight_kg']);
        self::assertSame('50.0', $children[0]['birth_length_cm']);
        self::assertSame($this->userId, (int) $children[0]['created_by']);
        self::assertSame('CREATE', $logs[0]['action']);
        self::assertSame('children', $logs[0]['entity']);
        self::assertSame((int) $children[0]['id'], (int) $logs[0]['entity_id']);
        self::assertSame('2001:db8::1', $logs[0]['ip_address']);
        self::assertNotEmpty($logs[0]['created_at']);
        $changes = json_decode($logs[0]['changes'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $changes['before']);
        self::assertSame($input['first_name'], $changes['after']['first_name']);
        self::assertSame($result->recordNumber, $changes['after']['record_no']);
        self::assertSame(($this->previousNumber ?? 0) + 1, $this->counter());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function test_AC04_duplicate_including_archived_child_warns_before_save_and_confirmation_can_continue(): void
    {
        $service = $this->service();
        $first = $service->register($this->input, $this->userId);
        $statement = $this->pdo->prepare('UPDATE children SET archived_at = :at, archive_reason = :reason WHERE record_no = :record');
        $statement->execute(['at' => '2026-10-10 12:00:00', 'reason' => 'FAKE Moved', 'record' => $first->recordNumber]);
        $count = $this->counter();
        $warning = $service->register($this->input, $this->userId);
        self::assertNull($warning->recordNumber);
        self::assertSame($first->recordNumber, $warning->duplicates[0]['record_no']);
        self::assertNotNull($warning->duplicates[0]['archived_at']);
        self::assertCount(1, $this->rows('children'));
        self::assertCount(1, $this->rows('audit'));
        self::assertSame($count, $this->counter());
        $second = $service->register($this->input, $this->userId, true);
        self::assertNotSame($first->recordNumber, $second->recordNumber);
        self::assertCount(2, $this->rows('children'));
        self::assertCount(2, $this->rows('audit'));
    }

    public function test_AC04_duplicate_match_uses_complete_name_and_birth_date_with_bound_input(): void
    {
        $this->service()->register($this->input, $this->userId);
        $validator = new ChildValidator();
        $repository = new ChildRepository($this->pdo);
        self::assertCount(1, $repository->duplicates($validator->validate(array_replace($this->input,
            ['first_name' => 'fake', 'middle_name' => 'child']))));
        self::assertSame([], $repository->duplicates($validator->validate(array_replace($this->input, ['suffix' => 'FAKE II']))));
        self::assertSame([], $repository->duplicates($validator->validate(array_replace($this->input,
            ['date_of_birth' => (new DateTimeImmutable('today'))->modify('-2 years')->format('Y-m-d')]))));
        self::assertSame([], $repository->duplicates($validator->validate(array_replace($this->input, ['last_name' => "' OR 1=1 --"]))));
    }

    public function test_AC11_failure_after_audit_insert_rolls_back_child_log_and_counter(): void
    {
        $audit = new class ($this->pdo) extends AuditRepository {
            public function insert(int $userId, string $action, string $entity, int $entityId, string $changes, ?string $ipAddress): void
            {
                parent::insert($userId, $action, $entity, $entityId, $changes, $ipAddress);
                throw new RuntimeException('FAKE audit failure');
            }
        };
        try {
            $this->service($audit)->register($this->input, $this->userId);
            self::fail('Failure was swallowed.');
        } catch (RuntimeException $error) {
            self::assertSame([], $this->rows('children'));
            self::assertSame([], $this->rows('audit'));
            self::assertSame($this->previousNumber, $this->counter());
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    public function test_AC11_child_insert_failure_rolls_back_counter_without_audit(): void
    {
        try {
            $this->service()->register($this->input, 4294967295);
            self::fail('Missing actor was accepted.');
        } catch (PDOException $error) {
            self::assertSame('23000', $error->getCode());
            self::assertSame([], $this->rows('children'));
            self::assertSame([], $this->rows('audit'));
            self::assertSame($this->previousNumber, $this->counter());
        }
    }

    /** Insert only fake fixtures; older dates represent children who aged after registration. */
    private function seedChild(array $changes = []): string
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        $data = array_replace((new ChildValidator())->validate($this->input), $changes);
        $data['record_no'] = (new RecordNumberGenerator(new RecordCounterRepository($this->pdo)))->generate($this->year);
        $data['created_by'] = $this->userId;
        (new ChildRepository($this->pdo))->insert($data);
        if ($ownsTransaction) {
            $this->pdo->commit();
        }

        return $data['record_no'];
    }

    private function searchChildren(array $query = [], ?DateTimeImmutable $today = null): array
    {
        return $this->service()->search((new ChildFilterValidator())->validate($query), $today);
    }

    public function test_AC12_search_matches_names_record_numbers_and_guardians_and_excludes_archived_by_default(): void
    {
        $record = $this->seedChild(['first_name' => 'FAKE %_! Quoted', 'guardian_name' => 'FAKE Unique Guardian']);
        $archived = $this->seedChild(['first_name' => 'FAKE Archived']);
        $statement = $this->pdo->prepare('UPDATE children SET archived_at = :at WHERE record_no = :record');
        $statement->execute(['at' => '2026-01-01 12:00:00', 'record' => $archived]);
        foreach (['%_!', 'FAKE Unique Guardian', $record, 'FAKE %_! Quoted ' . $this->input['last_name']] as $query) {
            $results = $this->searchChildren(['q' => $query]);
            self::assertSame(1, $results['total']);
            self::assertSame($record, $results['children'][0]['record_no']);
        }
        self::assertSame(1, $this->searchChildren(['q' => $this->input['last_name']])['total']);
        $includingArchived = $this->searchChildren(['q' => $this->input['last_name'], 'include_archived' => '1']);
        self::assertSame(2, $includingArchived['total']);
        self::assertCount(1, array_filter($includingArchived['children'],
            static fn (array $child): bool => $child['archived_at'] !== null));
        self::assertSame(0, $this->searchChildren(['q' => "' OR 1=1 --"])['total']);
        self::assertSame(0, $this->searchChildren(['q' => 'FAKE no matches ' . bin2hex(random_bytes(8))])['total']);
    }

    public function test_AC12_age_groups_use_completed_months_and_combine_with_purok_and_sex(): void
    {
        $today = new DateTimeImmutable('2026-10-10');
        $birthDates = [0 => '2026-10-10', 11 => '2025-10-11', 12 => '2025-10-10', 23 => '2024-10-11',
            24 => '2024-10-10', 35 => '2023-10-11', 36 => '2023-10-10', 47 => '2022-10-11',
            48 => '2022-10-10', 59 => '2021-10-11', 60 => '2021-10-10', 61 => '2021-09-10'];
        foreach ($birthDates as $months => $date) {
            $this->seedChild(['first_name' => sprintf('FAKE %02d months', $months), 'date_of_birth' => $date]);
        }
        $this->seedChild(['first_name' => 'FAKE Male', 'sex' => 'M', 'date_of_birth' => '2025-10-10']);
        $this->seedChild(['first_name' => 'FAKE Future', 'date_of_birth' => '2026-10-11']);
        foreach (['0-11' => [0, 11], '12-23' => [12, 23], '24-35' => [24, 35],
            '36-47' => [36, 47], '48-59' => [48, 59], '60+' => [60, 61]] as $group => $ages) {
            $results = $this->searchChildren(['q' => $this->input['last_name'], 'age_group' => $group,
                'sex' => 'F', 'purok_id' => (string) $this->purokId], $today);
            self::assertSame(2, $results['total']);
            self::assertSame($ages, array_map(static fn (array $row): int => (int) $row['age_months'], $results['children']));
            self::assertSame(1, count(array_unique(array_column($results['children'], 'purok_name'))));
        }
        self::assertSame(1, $this->searchChildren(['q' => $this->input['last_name'], 'sex' => 'M', 'age_group' => '12-23'], $today)['total']);
        self::assertSame(0, $this->searchChildren(['q' => $this->input['last_name'], 'purok_id' => '4294967295'], $today)['total']);
        self::assertSame(13, $this->searchChildren(['q' => $this->input['last_name']], $today)['total']);
    }

    public function test_AC12_pagination_is_stable_for_identical_names_and_clamps_past_last_page(): void
    {
        for ($index = 0; $index < 27; $index++) {
            $this->seedChild();
        }
        $query = ['q' => $this->input['last_name']];
        $first = $this->searchChildren($query);
        $second = $this->searchChildren($query + ['page' => '2']);
        self::assertSame(27, $first['total']);
        self::assertCount(25, $first['children']);
        self::assertCount(2, $second['children']);
        self::assertSame([26, 27, 2, 2], [$second['from'], $second['to'], $second['page'], $second['pages']]);
        $ids = array_merge(array_column($first['children'], 'id'), array_column($second['children'], 'id'));
        self::assertCount(27, array_unique($ids));
        $sorted = $ids;
        sort($sorted, SORT_NUMERIC);
        self::assertSame($sorted, $ids);
        self::assertSame($second, $this->searchChildren($query + ['page' => '2147483647']));
        $empty = $this->searchChildren(['q' => 'FAKE Missing ' . bin2hex(random_bytes(8))]);
        self::assertSame([0, 0, 1, 1], [$empty['from'], $empty['to'], $empty['page'], $empty['pages']]);
        self::assertSame([], $empty['children']);
    }

    public function test_AC12_search_returns_in_under_two_seconds_for_ten_thousand_fake_children(): void
    {
        $this->pdo->beginTransaction();
        for ($index = 0; $index < 10000; $index++) {
            $this->seedChild(['first_name' => sprintf('FAKE Child %05d', $index)]);
        }
        $this->pdo->commit();
        $started = hrtime(true);
        $results = $this->searchChildren(['q' => $this->input['last_name'], 'sex' => 'F',
            'purok_id' => (string) $this->purokId, 'age_group' => '12-23']);
        $seconds = (hrtime(true) - $started) / 1e9;
        self::assertSame(10000, $results['total']);
        self::assertCount(25, $results['children']);
        self::assertSame(400, $results['pages']);
        self::assertLessThan(2.0, $seconds, 'Search of 10,000 fake records exceeded the spec performance target.');
    }

    public function test_AC03_profile_loads_complete_demographics_and_archived_records_by_bound_id(): void
    {
        $record = $this->seedChild(['middle_name' => 'FAKE Middle', 'suffix' => 'FAKE II',
            'father_name' => 'FAKE Father', 'philhealth_no' => 'FAKE PhilHealth', 'notes' => '<FAKE Notes>',
            'birth_weight_kg' => '3.50', 'birth_length_cm' => '50.0']);
        $repository = new ChildRepository($this->pdo);
        $id = (int) $this->rows('children')[0]['id'];
        $child = $this->service()->profile((string) $id);
        self::assertNotNull($child);
        self::assertSame($record, $child['record_no']);
        self::assertSame('FAKE Child FAKE Middle ' . $this->input['last_name'] . ' FAKE II', $child['full_name']);
        self::assertStringStartsWith('FAKE Registration Purok', $child['purok_name']);
        self::assertSame(12, (int) $child['age_months']);
        self::assertSame('FAKE Father', $child['father_name']);
        self::assertSame('FAKE PhilHealth', $child['philhealth_no']);
        self::assertSame('<FAKE Notes>', $child['notes']);
        self::assertSame('3.50', $child['birth_weight_kg']);
        self::assertSame('50.0', $child['birth_length_cm']);
        self::assertNull($child['archived_at']);
        self::assertNull($repository->findById(4294967295, date('Y-m-d')));
        $statement = $this->pdo->prepare('UPDATE children SET archived_at = :at, archive_reason = :reason WHERE id = :id');
        $statement->execute(['at' => '2026-01-01 12:00:00', 'reason' => 'FAKE Moved Away', 'id' => $id]);
        $archived = $this->service()->profile((string) $id);
        self::assertSame('2026-01-01 12:00:00', $archived['archived_at']);
        self::assertSame('FAKE Moved Away', $archived['archive_reason']);
        self::assertSame([], $this->rows('audit'), 'Reading a profile must not write or change child data.');
        self::assertFalse($this->pdo->inTransaction());
    }

    public function test_AC11_edit_preserves_identity_and_logs_only_changed_demographics_for_older_children(): void
    {
        $date = (new DateTimeImmutable('today'))->modify('-6 years')->format('Y-m-d');
        $record = $this->seedChild(['date_of_birth' => $date]);
        $original = $this->rows('children')[0];
        $input = array_replace($this->input, ['date_of_birth' => $date, 'first_name' => "FAKE Changed 'quoted'",
            'notes' => '<FAKE Notes>', 'record_no' => 'FORGED', 'created_by' => '4294967295',
            'archived_at' => '2026-01-01', 'archive_reason' => 'FORGED']);
        $this->service()->update((string) $original['id'], $input, $this->userId);
        $child = $this->rows('children')[0];
        self::assertSame($record, $child['record_no']);
        self::assertSame($original['created_by'], $child['created_by']);
        self::assertSame($original['created_at'], $child['created_at']);
        self::assertNull($child['archived_at']);
        self::assertNull($child['archive_reason']);
        self::assertSame($input['first_name'], $child['first_name']);
        self::assertSame($date, $child['date_of_birth']);
        $logs = $this->rows('audit');
        self::assertCount(1, $logs);
        self::assertSame('UPDATE', $logs[0]['action']);
        $changes = json_decode($logs[0]['changes'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['first_name' => $original['first_name'], 'notes' => null], $changes['before']);
        self::assertSame(['first_name' => $input['first_name'], 'notes' => $input['notes']], $changes['after']);
        self::assertFalse($this->pdo->inTransaction());
        $this->service()->update((string) $original['id'], $input, $this->userId);
        $unchangedLog = $this->rows('audit')[1];
        $unchanged = json_decode($unchangedLog['changes'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['before' => [], 'after' => []], $unchanged);
    }

    public function test_AC12_archive_hides_child_and_restore_returns_it_with_both_reasons_in_audit(): void
    {
        $this->seedChild();
        $id = (string) $this->rows('children')[0]['id'];
        $service = $this->service();
        $service->archive($id, ['reason' => " FAKE Moved 'quoted' ", 'confirm' => '1'], $this->userId);
        $archived = $this->rows('children')[0];
        self::assertNotNull($archived['archived_at']);
        self::assertSame("FAKE Moved 'quoted'", $archived['archive_reason']);
        self::assertSame(0, $this->searchChildren(['q' => $this->input['last_name']])['total']);
        self::assertSame(1, $this->searchChildren(['q' => $this->input['last_name'], 'include_archived' => '1'])['total']);
        $service->restore($id, ['reason' => 'FAKE Returned', 'confirm' => '1'], $this->userId);
        $restored = $this->rows('children')[0];
        self::assertSame($archived['id'], $restored['id']);
        self::assertSame($archived['record_no'], $restored['record_no']);
        self::assertNull($restored['archived_at']);
        self::assertNull($restored['archive_reason']);
        self::assertSame(1, $this->searchChildren(['q' => $this->input['last_name']])['total']);
        $logs = $this->rows('audit');
        self::assertSame(['ARCHIVE', 'RESTORE'], array_column($logs, 'action'));
        foreach ($logs as $log) {
            self::assertSame($this->userId, (int) $log['user_id']);
            self::assertSame((int) $id, (int) $log['entity_id']);
            self::assertSame('children', $log['entity']);
            self::assertNotEmpty($log['created_at']);
        }
        $changes = json_decode($logs[1]['changes'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['archived_at' => $archived['archived_at'], 'archive_reason' => $archived['archive_reason']], $changes['before']);
        self::assertSame(['archived_at' => null, 'archive_reason' => null, 'restore_reason' => 'FAKE Returned'], $changes['after']);
    }

    public function test_AC11_repeated_archive_restore_and_missing_child_do_not_change_data_or_add_logs(): void
    {
        $this->seedChild();
        $id = (string) $this->rows('children')[0]['id'];
        $service = $this->service();
        $input = ['reason' => 'FAKE Reason', 'confirm' => '1'];
        foreach (['archive', 'restore'] as $action) {
            $service->$action($id, $input, $this->userId);
            $before = $this->rows('children');
            $logs = $this->rows('audit');
            try {
                $service->$action($id, $input, $this->userId);
                self::fail('Repeated status change was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('already', $error->getMessage());
                self::assertSame($before, $this->rows('children'));
                self::assertSame($logs, $this->rows('audit'));
                self::assertFalse($this->pdo->inTransaction());
            }
        }
        try {
            $service->archive('4294967295', $input, $this->userId);
            self::fail('Missing child was accepted.');
        } catch (OutOfBoundsException $error) {
            self::assertCount(2, $this->rows('audit'));
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    public static function childChanges(): array
    {
        return [['update'], ['archive'], ['restore']];
    }

    #[DataProvider('childChanges')]
    public function test_AC11_failure_after_audit_insert_rolls_back_edit_archive_and_restore(string $action): void
    {
        $this->seedChild();
        $id = (string) $this->rows('children')[0]['id'];
        if ($action === 'restore') {
            $this->service()->archive($id, ['reason' => 'FAKE Initial Archive', 'confirm' => '1'], $this->userId);
        }
        $before = $this->rows('children');
        $logs = $this->rows('audit');
        $number = $this->counter();
        $audit = new class ($this->pdo) extends AuditRepository {
            public function insert(int $userId, string $action, string $entity, int $entityId, string $changes, ?string $ipAddress): void
            {
                parent::insert($userId, $action, $entity, $entityId, $changes, $ipAddress);
                throw new RuntimeException('FAKE audit failure');
            }
        };
        $input = $action === 'update' ? array_replace($this->input, ['first_name' => 'FAKE Changed'])
            : ['reason' => 'FAKE New Reason', 'confirm' => '1'];
        try {
            $this->service($audit)->$action($id, $input, $this->userId);
            self::fail('Audit failure was swallowed.');
        } catch (RuntimeException $error) {
            self::assertSame($before, $this->rows('children'));
            self::assertSame($logs, $this->rows('audit'));
            self::assertSame($number, $this->counter());
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    public function test_AC11_child_repository_rejects_autocommit_changes(): void
    {
        $repository = new ChildRepository($this->pdo);
        foreach (['lockById' => [9], 'update' => [9, []], 'setArchive' => [9, null, null]] as $method => $arguments) {
            try {
                $repository->$method(...$arguments);
                self::fail('Child change outside a transaction was accepted.');
            } catch (LogicException $error) {
                self::assertStringContainsString('transaction', $error->getMessage());
            }
        }
    }
}
