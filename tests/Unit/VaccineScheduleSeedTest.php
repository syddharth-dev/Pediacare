<?php

declare(strict_types=1);

use Pediacare\Repositories\VaccineScheduleRepository;
use Pediacare\Services\VaccineScheduleSeedService;
use PHPUnit\Framework\TestCase;

final class VaccineScheduleSeedTest extends TestCase
{
    public function test_AC07_AC08_draft_seed_matches_approved_ages_intervals_and_nine_FIC_doses(): void
    {
        $seed = require dirname(__DIR__, 2) . '/database/seeds/vaccine-schedule.php';
        self::assertSame(['BCG', 'HEPB_BIRTH', 'PENTA', 'BOPV', 'IPV', 'PCV', 'MCV'], array_column($seed, 'code'));
        self::assertSame([[98, null, 0, null, 0], [null, 9, null, 4, 0]], $seed[4]['doses']);
        self::assertSame([[null, 9, 0, null, 1], [null, 12, 28, null, 1]], $seed[6]['doses']);
        foreach ([2, 3, 5] as $index) {
            self::assertSame([42, 70, 98], array_column($seed[$index]['doses'], 0));
            self::assertSame([0, 28, 28], array_column($seed[$index]['doses'], 2));
        }
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $repository = $this->createMock(VaccineScheduleRepository::class);
        $repository->expects(self::exactly(7))->method('ensureVaccine')->willReturn(5);
        $rows = [];
        $repository->expects(self::exactly(15))->method('ensureDose')->willReturnCallback(
            static function (array $row) use (&$rows): void { $rows[] = $row; }
        );
        self::assertSame(15, (new VaccineScheduleSeedService($pdo, $repository))->run($seed));
        self::assertSame(9, array_sum(array_column($rows, 'counts_toward_fic')));
        self::assertSame(array_fill(0, 15, 0), array_column($rows, 'grace_days'));
        self::assertSame(range(1, 15), array_column($rows, 'sort_order'));
    }

    public function test_AC07_seed_failure_rolls_back_and_never_commits(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $pdo->expects(self::never())->method('commit');
        $repository = $this->createMock(VaccineScheduleRepository::class);
        $repository->method('ensureVaccine')->willReturn(5);
        $repository->method('ensureDose')->willThrowException(new RuntimeException('FAKE seed failure'));
        $this->expectException(RuntimeException::class);
        (new VaccineScheduleSeedService($pdo, $repository))->run(
            require dirname(__DIR__, 2) . '/database/seeds/vaccine-schedule.php'
        );
    }

    public function test_AC07_seed_refuses_a_callers_transaction_without_changing_it(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('rollBack');
        $repository = $this->createMock(VaccineScheduleRepository::class);
        $repository->expects(self::never())->method('ensureVaccine');
        $this->expectException(LogicException::class);
        (new VaccineScheduleSeedService($pdo, $repository))->run([]);
    }
}
