<?php

declare(strict_types=1);

namespace Pediacare\Services;

use DateTimeImmutable;
use RuntimeException;
use Pediacare\Domain\Enums\Sex;
use Pediacare\Domain\Growth\AgeInMonths;
use Pediacare\Domain\Growth\LmsTable;
use Pediacare\Domain\Immunization\DoseStatusCalculator;
use Pediacare\Repositories\{DemoSeedRepository, PurokRepository, UserRepository, VaccineScheduleRepository, WhoLmsRepository};

final class DemoSeedService
{
    public function __construct(
        private readonly DemoSeedRepository $demo,
        private readonly UserRepository $users,
        private readonly PurokRepository $puroks,
        private readonly VaccineScheduleRepository $schedule,
        private readonly WhoLmsRepository $references,
        private readonly ChildService $children,
        private readonly MeasurementService $measurements,
        private readonly ImmunizationService $immunizations,
        private readonly VisitService $visits,
    ) {
    }

    /** @return array{children: int, measurements: int, immunizations: int, visits: int, archived: int} */
    public function run(int $actor, ?DateTimeImmutable $today = null): array
    {
        if ($this->demo->hasChildren()) {
            throw new RuntimeException('Demo seeding requires an empty child registry; existing records were preserved.');
        }
        $user = $this->users->findById($actor);
        if ($user === null || (int) $user['active'] !== 1 || $user['role'] !== 'ADMIN') {
            throw new RuntimeException('Demo seeding requires an existing active Admin.');
        }
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $table = $this->references->load();
        $vaccines = $this->schedule->all();
        $doses = [];
        foreach ($vaccines as $vaccine) {
            if ((int) $vaccine['active'] === 1) {
                array_push($doses, ...$vaccine['doses']);
            }
        }
        if ($doses === []) {
            throw new RuntimeException('Seed the vaccine schedule before demo data.');
        }
        usort($doses, static fn (array $a, array $b): int => $a['dose_number'] <=> $b['dose_number']);
        // Check reference availability before writing any demo records.
        foreach ([Sex::MALE, Sex::FEMALE] as $sex) {
            if ($table->at('WHO2006', 'WFA', $sex, 0) === null || $table->at('WHO2006', 'HFA', $sex, 0) === null) {
                throw new RuntimeException('Required demo growth references are missing.');
            }
        }
        $puroks = [];
        foreach ($this->puroks->all() as $purok) {
            $puroks[$purok['name']] = (int) $purok['id'];
        }
        $ids = [];
        for ($number = 1; $number <= 5; $number++) {
            $name = 'FAKE Demo Purok ' . $number;
            if (!isset($puroks[$name])) {
                $this->puroks->insert($name);
            }
        }
        foreach ($this->puroks->all() as $purok) {
            if (preg_match('/\AFAKE Demo Purok [1-5]\z/', $purok['name']) === 1) {
                $ids[] = (int) $purok['id'];
            }
        }
        $counts = ['children' => 0, 'measurements' => 0, 'immunizations' => 0, 'visits' => 0, 'archived' => 0];
        for ($number = 1; $number <= 200; $number++) {
            $birth = $today->modify('first day of this month')->modify('-' . (($number - 1) % 60) . ' months');
            $sex = ($number + intdiv($number - 1, 60)) % 2 === 0 ? Sex::MALE : Sex::FEMALE;
            $input = $this->child($number, $birth, $sex, $ids[($number - 1) % 5]);
            $result = $this->children->register($input, $actor, false, $today);
            $id = $result->childId ?? throw new RuntimeException('Unexpected duplicate in demo data.');
            $counts['children']++;
            if ($number % 10 !== 0) {
                $dates = [$today];
                if ($number % 4 === 0 && $today->modify('-1 month') >= $birth) {
                    array_unshift($dates, $today->modify('-1 month'));
                }
                foreach ($dates as $date) {
                    $measurement = $this->measurements->record((string) $id,
                        $this->measurement($number, $birth, $sex, $date, $table), $actor, $today);
                    if ($measurement->id === null) {
                        throw new RuntimeException('Demo measurement unexpectedly requires confirmation.');
                    }
                    $counts['measurements']++;
                }
            }
            $counts['immunizations'] += $this->vaccinate($id, $number, $birth, $doses, $actor, $today);
            if ($number % 2 === 0) {
                $referred = $number % 8 === 0;
                $this->visits->record((string) $id, [
                    'visit_date' => $today->format('Y-m-d'), 'reason' => 'FAKE routine follow-up',
                    'findings' => 'FAKE demonstration findings; not a clinical record.',
                    'actions' => 'FAKE counselling example', 'referred' => $referred ? '1' : '0',
                    'referred_to' => $referred ? 'FAKE Demo Referral Center' : '',
                    'notes' => 'FAKE DATA ONLY',
                    'supplements' => [($number % 4 === 0 ? 'VITAMIN_A' : 'MNP') => [
                        'dose' => 'FAKE 1 mL; demonstration only, not dosing guidance',
                        'date_given' => $today->format('Y-m-d'),
                    ]],
                ], $actor, $today);
                $counts['visits']++;
            }
            if ($number % 20 === 19) {
                $this->children->archive((string) $id, ['reason' => 'FAKE family moved; demo archive', 'confirm' => '1'], $actor);
                $counts['archived']++;
            }
        }

        return $counts;
    }

    private function child(int $number, DateTimeImmutable $birth, Sex $sex, int $purok): array
    {
        $label = str_pad((string) $number, 3, '0', STR_PAD_LEFT);

        return [
            'first_name' => 'FAKE Child ' . $label, 'middle_name' => $number % 3 === 0 ? 'FAKE Middle' : '',
            'last_name' => 'FAKE Demo Family ' . $label, 'suffix' => '', 'sex' => $sex->value,
            'date_of_birth' => $birth->format('Y-m-d'), 'purok_id' => (string) $purok,
            'place_of_birth' => $number % 2 === 0 ? 'FAKE Demo Hospital' : 'FAKE Demo Health Center',
            'address' => 'FAKE House ' . $label . ', Demo Street (fictional address)',
            'mother_name' => 'FAKE Mother ' . $label, 'father_name' => $number % 3 === 0 ? '' : 'FAKE Father ' . $label,
            'guardian_name' => 'FAKE Guardian ' . $label, 'guardian_relation' => $number % 4 === 0 ? 'Grandparent' : 'Mother',
            'contact_no' => 'FAKE-NOT-A-PHONE-' . $label, 'philhealth_no' => '',
            'birth_weight_kg' => '3.20', 'birth_length_cm' => '49.0', 'notes' => 'FAKE DATA ONLY; generated for demos and testing.',
        ];
    }

    private function measurement(int $number, DateTimeImmutable $birth, Sex $sex, DateTimeImmutable $date, LmsTable $table): array
    {
        $age = AgeInMonths::between($birth, $date);
        $weight = $table->at('WHO2006', 'WFA', $sex, $age->days);
        $height = $table->at('WHO2006', 'HFA', $sex, $age->days);
        if ($weight === null || $height === null) {
            throw new RuntimeException('Required demo growth references are missing.');
        }
        // Synthetic variation around WHO medians; these factors are demo values, not clinical thresholds.
        $factor = [0.8, 1.0, 1.0, 1.25, 1.0][$number % 5];

        return ['measured_at' => $date->format('Y-m-d'),
            'weight_kg' => number_format($weight->m * $factor, 2, '.', ''),
            'height_cm' => number_format($height->m, 1, '.', ''),
            // Match the reference posture: WHO Anthro manual §3.2.3, cited in Who2006Standard.
            'position' => $age->days < 731 ? 'LYING' : 'STANDING', 'muac_cm' => ''];
    }

    private function vaccinate(int $id, int $number, DateTimeImmutable $birth, array $doses, int $actor, DateTimeImmutable $today): int
    {
        $records = [];
        if ($number % 3 === 0) {
            return 0;
        }
        $calculator = new DoseStatusCalculator();
        foreach ($doses as $dose) {
            if ($number % 3 === 1 && $dose['dose_number'] !== 1) {
                continue;
            }
            // Eligibility and intervals come from the configured schedule, through the existing domain calculator.
            $dates = $calculator->earliestEligibleDates($birth, $doses, $records);
            $date = $dates[$dose['id']] ?? null;
            if ($date === null || $date > $today) {
                continue;
            }
            $date = $number % 3 === 1 ? $today : $date;
            $this->immunizations->record((string) $id, [
                'schedule_dose_id' => (string) $dose['id'], 'date_given' => $date->format('Y-m-d'),
                'lot_number' => 'FAKE-DEMO-LOT', 'site' => 'FAKE demonstration site',
                'given_elsewhere' => $number % 2 === 0 ? '1' : '0',
                'place_given' => $number % 2 === 0 ? 'FAKE Demo Hospital' : 'FAKE Demo Health Center',
            ], $actor, $today);
            $records[] = ['schedule_dose_id' => $dose['id'], 'date_given' => $date->format('Y-m-d')];
        }

        return count($records);
    }
}
