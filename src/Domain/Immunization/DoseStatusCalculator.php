<?php

declare(strict_types=1);

namespace Pediacare\Domain\Immunization;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Pediacare\Domain\Enums\DoseStatus;

final class DoseStatusCalculator
{
    /**
     * Rows are loaded for one child by the calling service. Keys in the result
     * are schedule_doses.id, independent of input order or display order.
     *
     * @param list<array<string, int|null>> $scheduleDoses
     * @param list<array{schedule_dose_id: int, date_given: string}> $immunizations
     * @return array<int, DoseStatus>
     */
    public function calculate(DateTimeImmutable $birthDate, array $scheduleDoses, array $immunizations, DateTimeImmutable $today): array
    {
        $birth = $this->calendarDate($birthDate);
        $date = $this->calendarDate($today);
        if ($date < $birth) {
            throw new InvalidArgumentException('Calculation date must be on or after birth date.');
        }
        $doses = $this->doses($scheduleDoses);
        $given = $this->given($immunizations, $doses, $birth);
        $statuses = [];
        // Source: specs/001-pediacare-mvp/plan.md §6.4, approved AC-07 rules.
        foreach ($doses as $id => $dose) {
            $recommended = $this->add($birth, $dose['recommended_age_days'], $dose['recommended_age_months']);
            $end = $this->add($recommended, $dose['grace_days'], null);
            $statuses[$id] = match (true) {
                isset($given[$id]) => DoseStatus::GIVEN,
                $date < $recommended => DoseStatus::UPCOMING,
                $date <= $end => DoseStatus::DUE,
                default => DoseStatus::OVERDUE,
            };
        }

        return $statuses;
    }

    /**
     * Null means the previous dose is missing. Status does not certify eligibility.
     *
     * @param list<array<string, int|null>> $scheduleDoses
     * @param list<array{schedule_dose_id: int, date_given: string}> $immunizations
     * @return array<int, DateTimeImmutable|null>
     */
    public function earliestEligibleDates(DateTimeImmutable $birthDate, array $scheduleDoses, array $immunizations): array
    {
        $birth = $this->calendarDate($birthDate);
        $doses = $this->doses($scheduleDoses);
        $given = $this->given($immunizations, $doses, $birth);
        $series = [];
        foreach ($doses as $id => $dose) {
            $series[$dose['vaccine_id']][$dose['dose_number']] = $id;
        }
        $dates = [];
        // Source: plan.md §6.4 approved interval rules; values come from the schedule.
        foreach ($doses as $id => $dose) {
            $recommended = $this->add($birth, $dose['recommended_age_days'], $dose['recommended_age_months']);
            if ($dose['dose_number'] === 1) {
                $dates[$id] = $recommended;
                continue;
            }
            $previousId = $series[$dose['vaccine_id']][$dose['dose_number'] - 1] ?? null;
            $previous = $previousId === null ? null : ($given[$previousId] ?? null);
            $dates[$id] = $previous === null ? null : max($recommended,
                $this->add($previous, $dose['min_interval_days'], $dose['min_interval_months']));
        }

        return $dates;
    }

    /** @return array<int, array<string, int|null>> */
    private function doses(array $rows): array
    {
        $doses = [];
        $series = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Invalid schedule dose.');
            }
            foreach (['id', 'vaccine_id', 'dose_number', 'grace_days'] as $field) {
                if (!isset($row[$field]) || !is_int($row[$field]) || $row[$field] < ($field === 'grace_days' ? 0 : 1)
                    || $row[$field] > 4294967295) {
                    throw new InvalidArgumentException('Invalid schedule identity or grace period.');
                }
            }
            foreach ([['recommended_age_days', 'recommended_age_months'], ['min_interval_days', 'min_interval_months']] as [$days, $months]) {
                if (!array_key_exists($days, $row) || !array_key_exists($months, $row)
                    || (($row[$days] === null) === ($row[$months] === null))) {
                    throw new InvalidArgumentException('Each schedule age and interval must have exactly one unit.');
                }
                $value = $row[$days] ?? $row[$months];
                if (!is_int($value) || $value < 0 || $value > 4294967295) {
                    throw new InvalidArgumentException('Schedule ages and intervals must be nonnegative whole numbers.');
                }
            }
            if (isset($doses[$row['id']]) || isset($series[$row['vaccine_id']][$row['dose_number']])) {
                throw new InvalidArgumentException('Duplicate schedule dose identity.');
            }
            $series[$row['vaccine_id']][$row['dose_number']] = true;
            $doses[$row['id']] = $row;
        }

        return $doses;
    }

    /** @return array<int, DateTimeImmutable> */
    private function given(array $rows, array $doses, DateTimeImmutable $birth): array
    {
        $given = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['schedule_dose_id']) || !is_int($row['schedule_dose_id'])
                || $row['schedule_dose_id'] < 1) {
                throw new InvalidArgumentException('Invalid immunization dose identity.');
            }
            if (!isset($doses[$row['schedule_dose_id']])) {
                continue;
            }
            $value = $row['date_given'] ?? null;
            if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) !== 1) {
                throw new InvalidArgumentException('Invalid vaccination date.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d') !== $value || $date < $birth) {
                throw new InvalidArgumentException('Vaccination date must be a valid date on or after birth.');
            }
            if (isset($given[$row['schedule_dose_id']])) {
                throw new InvalidArgumentException('Duplicate immunization for a scheduled dose.');
            }
            $given[$row['schedule_dose_id']] = $date;
        }

        return $given;
    }

    private function calendarDate(DateTimeImmutable $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d') . 'T00:00:00+00:00');
    }

    private function add(DateTimeImmutable $date, ?int $days, ?int $months): DateTimeImmutable
    {
        if ($days !== null) {
            $result = $date->modify('+' . $days . ' days');
        } else {
            // plan.md §6.4: clamp calendar-month addition instead of PHP's month overflow.
            $index = (int) $date->format('Y') * 12 + (int) $date->format('n') - 1 + $months;
            $year = intdiv($index, 12);
            if ($year > 9999) {
                throw new InvalidArgumentException('Schedule date exceeds the supported calendar.');
            }
            $first = $date->setDate($year, $index % 12 + 1, 1);
            $result = $first->setDate($year, $index % 12 + 1, min((int) $date->format('j'), (int) $first->format('t')));
        }
        if ($result === false || (int) $result->format('Y') > 9999) {
            throw new InvalidArgumentException('Schedule date exceeds the supported calendar.');
        }

        return $result;
    }
}
