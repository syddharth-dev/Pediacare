<?php

declare(strict_types=1);

use Pediacare\Core\Database;
use Pediacare\Repositories\MigrationRepository;
use Pediacare\Repositories\PurokRepository;
use Pediacare\Repositories\UserRepository;
use Pediacare\Services\MigrationService;
use Pediacare\Services\PurokService;
use Pediacare\Validation\PurokValidator;
use PHPUnit\Framework\TestCase;

final class RegistryFoundationTest extends TestCase
{
    private PDO $pdo;
    private PurokRepository $puroks;
    private int $purokId;
    private int $userId;

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
        $users = new UserRepository($this->pdo);
        $email = 'fake-registry-' . bin2hex(random_bytes(8)) . '@example.invalid';
        $users->insertAdmin('FAKE Registry Admin', $email, 'FAKE_UNUSED_HASH');
        $this->userId = (int) $users->findByEmail($email)['id'];
        $this->puroks = new PurokRepository($this->pdo);
        $this->puroks->insert('FAKE Purok ' . bin2hex(random_bytes(8)));
        $this->purokId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function insertChild(string $recordNo, ?int $purokId = null, ?int $userId = null): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO children (record_no, first_name, last_name, sex, date_of_birth, place_of_birth, '
            . 'purok_id, address, mother_name, guardian_name, guardian_relation, contact_no, created_by) '
            . 'VALUES (:record_no, :first_name, :last_name, :sex, :birth_date, :place, :purok, :address, '
            . ':mother, :guardian, :relation, :contact, :creator)'
        );
        $statement->execute([
            'record_no' => $recordNo, 'first_name' => 'FAKE Child', 'last_name' => 'FAKE Registry',
            'sex' => 'F', 'birth_date' => '2025-01-01', 'place' => 'FAKE Birthplace',
            'purok' => $purokId ?? $this->purokId, 'address' => 'FAKE Address', 'mother' => 'FAKE Mother',
            'guardian' => 'FAKE Guardian', 'relation' => 'FAKE Relation', 'contact' => 'FAKE Not a phone',
            'creator' => $userId ?? $this->userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function test_T15_purok_rename_keeps_child_relationship_and_rejects_duplicate_names(): void
    {
        $name = "FAKE 'quoted " . bin2hex(random_bytes(8));
        $service = new PurokService($this->puroks, new PurokValidator());
        $childId = $this->insertChild('PC-2026-00101');
        $service->rename((string) $this->purokId, ['name' => $name]);
        self::assertSame($name, $this->puroks->findById($this->purokId)['name']);
        $statement = $this->pdo->prepare('SELECT purok_id FROM children WHERE id = :id');
        $statement->execute(['id' => $childId]);
        self::assertSame($this->purokId, (int) $statement->fetchColumn());
        $listed = array_column($this->puroks->all(), 'name', 'id');
        self::assertSame($name, $listed[$this->purokId]);
        $this->expectException(InvalidArgumentException::class);
        $service->create(['name' => strtolower($name)]);
    }

    public function test_AC05_child_schema_rejects_duplicate_record_numbers(): void
    {
        $this->insertChild('PC-2026-00101');
        $this->expectException(PDOException::class);
        $this->expectExceptionCode('23000');
        $this->insertChild('PC-2026-00101');
    }

    public function test_T15_child_foreign_keys_reject_missing_purok_and_creator(): void
    {
        foreach (['purok', 'creator'] as $missing) {
            try {
                $this->insertChild('PC-2026-00101', $missing === 'purok' ? 4294967295 : null,
                    $missing === 'creator' ? 4294967295 : null);
                self::fail('Missing child relationship was accepted.');
            } catch (PDOException $error) {
                self::assertSame('23000', $error->getCode());
            }
        }
    }

    public function test_T15_child_relationships_prevent_cascading_deletion(): void
    {
        $this->insertChild('PC-2026-00101');
        foreach (['DELETE FROM puroks WHERE id = :id' => $this->purokId,
            'DELETE FROM users WHERE id = :id' => $this->userId] as $sql => $id) {
            try {
                $statement = $this->pdo->prepare($sql);
                $statement->execute(['id' => $id]);
                self::fail('Referenced record was deleted.');
            } catch (PDOException $error) {
                self::assertSame('23000', $error->getCode());
            }
        }
    }

    public function test_AC12_child_schema_preserves_optional_and_archive_fields(): void
    {
        $id = $this->insertChild('PC-2026-00101');
        $statement = $this->pdo->prepare('SELECT * FROM children WHERE id = :id');
        $statement->execute(['id' => $id]);
        $child = $statement->fetch();
        foreach (['middle_name', 'suffix', 'father_name', 'philhealth_no', 'birth_weight_kg',
            'birth_length_cm', 'notes', 'archived_at', 'archive_reason'] as $field) {
            self::assertNull($child[$field]);
        }
        self::assertNotEmpty($child['created_at']);
        self::assertNotEmpty($child['updated_at']);
        $archive = $this->pdo->prepare('UPDATE children SET archived_at = :at, archive_reason = :reason WHERE id = :id');
        $archive->execute(['at' => '2026-10-10 12:00:00', 'reason' => 'FAKE Moved', 'id' => $id]);
        $statement->execute(['id' => $id]);
        $archived = $statement->fetch();
        self::assertSame('FAKE Moved', $archived['archive_reason']);
        self::assertSame('FAKE Child', $archived['first_name']);
    }

    public function test_T15_migrations_are_tracked_once_and_counter_year_is_unique(): void
    {
        $this->pdo->rollBack();
        $migrations = new MigrationRepository($this->pdo);
        self::assertSame([], (new MigrationService($migrations))->run(dirname(__DIR__, 2) . '/database/migrations'));
        foreach (['004_create_puroks.sql', '005_create_children.sql', '006_create_record_counters.sql'] as $file) {
            self::assertSame(1, count(array_keys($migrations->applied(), $file, true)));
        }
        $this->pdo->beginTransaction();
        $statement = $this->pdo->prepare('INSERT INTO record_counters (year) VALUES (:year)');
        $statement->execute(['year' => 9999]);
        $read = $this->pdo->prepare('SELECT last_number FROM record_counters WHERE year = :year');
        $read->execute(['year' => 9999]);
        self::assertSame(0, (int) $read->fetchColumn());
        $this->expectException(PDOException::class);
        $this->expectExceptionCode('23000');
        $statement->execute(['year' => 9999]);
    }
}
