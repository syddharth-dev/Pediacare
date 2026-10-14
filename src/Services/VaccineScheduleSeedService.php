<?php

declare(strict_types=1);

namespace Pediacare\Services;

use LogicException;
use PDO;
use Pediacare\Repositories\VaccineScheduleRepository;
use Throwable;

final class VaccineScheduleSeedService
{
    public function __construct(private readonly PDO $pdo, private readonly VaccineScheduleRepository $schedule)
    {
    }

    /** @param list<array{code: string, name: string, doses: list<array{?int, ?int, ?int, ?int, int}>}> $vaccines */
    public function run(array $vaccines): int
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Vaccine seeding requires its own transaction.');
        }
        $count = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($vaccines as $vaccine) {
                $id = $this->schedule->ensureVaccine($vaccine['code'], $vaccine['name']);
                foreach ($vaccine['doses'] as $index => [$ageDays, $ageMonths, $intervalDays, $intervalMonths, $fic]) {
                    $this->schedule->ensureDose(['vaccine_id' => $id, 'dose_number' => $index + 1,
                        'recommended_age_days' => $ageDays, 'recommended_age_months' => $ageMonths,
                        'grace_days' => 0, 'min_interval_days' => $intervalDays, 'min_interval_months' => $intervalMonths,
                        'counts_toward_fic' => $fic, 'sort_order' => ++$count]);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return $count;
    }
}
