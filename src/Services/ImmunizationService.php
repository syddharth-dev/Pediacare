<?php

declare(strict_types=1);

namespace Pediacare\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use PDO;
use PDOException;
use Pediacare\Domain\Enums\AuditAction;
use Pediacare\Domain\Enums\DoseStatus;
use Pediacare\Domain\Immunization\DoseStatusCalculator;
use Pediacare\Domain\Immunization\FicChecker;
use Pediacare\Repositories\ChildRepository;
use Pediacare\Repositories\ImmunizationRepository;
use Pediacare\Repositories\VaccineScheduleRepository;
use Pediacare\Support\AuditLogger;
use Pediacare\Validation\ImmunizationValidator;
use Throwable;

final class ImmunizationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ChildRepository $children,
        private readonly VaccineScheduleRepository $schedule,
        private readonly ImmunizationRepository $immunizations,
        private readonly ImmunizationValidator $validator,
        private readonly AuditLogger $audit
    ) {
    }

    public function forChild(array $child, ?DateTimeImmutable $today = null): array
    {
        $vaccines = $this->schedule->all();
        $doses = $this->doses($vaccines);
        $records = $this->immunizations->forChild((int) $child['id']);
        $birth = new DateTimeImmutable($child['date_of_birth']);
        $calculator = new DoseStatusCalculator();
        $statuses = $calculator->calculate($birth, $doses, $records, $today ?? new DateTimeImmutable('today'));
        $dates = $calculator->earliestEligibleDates($birth, $doses, $records);
        $given = [];
        $available = 0;
        foreach ($records as $record) {
            $given[$record['schedule_dose_id']] = $record;
        }
        foreach ($vaccines as &$vaccine) {
            foreach ($vaccine['doses'] as &$dose) {
                $dose['status'] = $statuses[$dose['id']]->value;
                $dose['earliest_date'] = $dates[$dose['id']]?->format('Y-m-d');
                $dose['record'] = $given[$dose['id']] ?? null;
                $dose['can_record'] = (int) $vaccine['active'] === 1 && $dose['record'] === null;
                $available += $dose['can_record'] ? 1 : 0;
            }
            unset($dose);
        }
        unset($vaccine);

        return ['vaccines' => $vaccines, 'fic' => (new FicChecker())->isFullyImmunized($doses, $statuses), 'available' => $available];
    }

    public function record(string $childId, array $input, int $actor, ?DateTimeImmutable $today = null): int
    {
        if (preg_match('/\A[1-9][0-9]{0,9}\z/', $childId) !== 1 || (float) $childId > 4294967295) {
            throw new OutOfBoundsException('Child record not found.');
        }
        if ($actor < 1) {
            throw new InvalidArgumentException('Sign in again before saving.');
        }
        $data = $this->validator->validate($input, $today);
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Immunization creation must own its transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $child = $this->children->lockById((int) $childId) ?? throw new OutOfBoundsException('Child record not found.');
            if ($child['archived_at'] !== null) {
                throw new InvalidArgumentException('Restore this child record before recording a vaccine.');
            }
            if ($data['date_given'] < $child['date_of_birth']) {
                throw new InvalidArgumentException('Vaccination date must be on or after birth.');
            }
            $dose = $this->schedule->dose($data['schedule_dose_id'], true);
            if ($dose === null) {
                throw new InvalidArgumentException('Choose an existing scheduled vaccine dose.');
            }
            $vaccine = $this->schedule->vaccine((int) $dose['vaccine_id'], true);
            if ($vaccine === null || (int) $vaccine['active'] !== 1) {
                throw new InvalidArgumentException('This vaccine is inactive. Ask an Admin to review the schedule.');
            }
            $doses = $this->doses($this->schedule->all());
            $records = $this->immunizations->forChild((int) $childId);
            foreach ($records as $record) {
                if ((int) $record['schedule_dose_id'] === $data['schedule_dose_id']) {
                    throw new InvalidArgumentException('This dose is already recorded for this child.');
                }
            }
            // plan.md §6.4: recommended age and prior-dose interval govern recording separately from status.
            $earliest = (new DoseStatusCalculator())->earliestEligibleDates(new DateTimeImmutable($child['date_of_birth']), $doses, $records);
            $date = $earliest[$data['schedule_dose_id']] ?? null;
            if ($date === null) {
                throw new InvalidArgumentException('Record the previous dose before adding this dose.');
            }
            if ($data['date_given'] < $date->format('Y-m-d')) {
                throw new InvalidArgumentException('The configured earliest date for this dose is ' . $date->format('Y-m-d') . '. Check the vaccination date and schedule.');
            }
            $data += ['child_id' => (int) $childId, 'given_by' => $actor];
            $id = $this->immunizations->insert($data);
            $this->audit->log($actor, AuditAction::CREATE, 'immunizations', $id, [], $data);
            $this->pdo->commit();

            return $id;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) {
                throw new InvalidArgumentException('This dose is already recorded for this child.');
            }
            throw $error;
        }
    }

    public function defaulters(array $input, ?DateTimeImmutable $today = null, bool $all = false): array
    {
        $filters = (new \Pediacare\Validation\DefaulterFilterValidator())->validate($input);
        $vaccines = $this->schedule->all();
        $doses = [];
        $labels = [];
        foreach ($vaccines as $vaccine) {
            if ($filters['vaccine_id'] !== '' && (int) $vaccine['id'] !== (int) $filters['vaccine_id']) {
                continue;
            }
            foreach ($vaccine['doses'] as $dose) {
                $doses[] = $dose;
                $labels[$dose['id']] = $vaccine['name'] . ' — dose ' . $dose['dose_number'];
            }
        }
        $rows = [];
        $after = 0;
        $today ??= new DateTimeImmutable('today');
        $calculator = new DoseStatusCalculator();
        // plan.md §6.4 / AC-09: use the same overdue rule as the child's profile.
        while ($doses !== []) {
            $batch = $this->children->immunizationBatch($after, $filters['purok_id'] === '' ? null : (int) $filters['purok_id']);
            if ($batch === []) {
                break;
            }
            $records = $this->immunizations->forChildren(array_map(static fn (array $child): int => (int) $child['id'], $batch));
            foreach ($batch as $child) {
                $statuses = $calculator->calculate(new DateTimeImmutable($child['date_of_birth']), $doses,
                    $records[$child['id']] ?? [], $today);
                $overdue = [];
                foreach ($statuses as $id => $status) {
                    if ($status === DoseStatus::OVERDUE) {
                        $overdue[] = $labels[$id];
                    }
                }
                if ($overdue !== []) {
                    $rows[] = $child + ['overdue' => $overdue];
                }
                $after = (int) $child['id'];
            }
        }
        $total = count($rows);
        $limit = $all ? max(1, $total) : 25;
        $pages = max(1, (int) ceil($total / $limit));
        $page = min((int) $filters['page'], $pages);
        $items = array_slice($rows, ($page - 1) * $limit, $limit);

        return ['children' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages,
            'from' => $total === 0 ? 0 : ($page - 1) * $limit + 1,
            'to' => min($page * $limit, $total), 'filters' => $filters, 'vaccines' => $vaccines];
    }

    private function doses(array $vaccines): array
    {
        $doses = [];
        foreach ($vaccines as $vaccine) {
            array_push($doses, ...$vaccine['doses']);
        }

        return $doses;
    }
}
