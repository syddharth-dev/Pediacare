<?php

declare(strict_types=1);

namespace Pediacare\Services;

use InvalidArgumentException;
use Pediacare\Repositories\ImmunizationRepository;
use Pediacare\Repositories\MeasurementRepository;
use Pediacare\Repositories\SupplementRepository;
use Pediacare\Repositories\VisitRepository;
use Pediacare\Validation\VisitValidator;

final class HealthTimelineService
{
    public function __construct(
        private readonly MeasurementRepository $measurements,
        private readonly ImmunizationRepository $immunizations,
        private readonly VisitRepository $visits,
        private readonly SupplementRepository $supplements
    ) {
    }

    public function forChild(int $childId, array $input = []): array
    {
        $page = $input['page'] ?? '1';
        if ($childId < 1 || !is_string($page) || preg_match('/\A[1-9][0-9]{0,9}\z/', $page) !== 1
            || (float) $page > 2147483647) {
            throw new InvalidArgumentException('Choose a valid health timeline page.');
        }
        $events = [];
        foreach ($this->measurements->history($childId) as $row) {
            $details = $this->fields($row, ['age_months' => 'Recorded age (completed months)', 'weight_kg' => 'Weight (kg)',
                'height_cm' => 'Length/height (cm)', 'muac_cm' => 'MUAC (cm)', 'bmi' => 'BMI (kg/m²)',
                'measured_by_name' => 'Measured by']);
            $details['Position'] = $row['position'] === 'LYING' ? 'Lying' : 'Standing';
            foreach (['wfa' => 'Weight-for-age', 'hfa' => 'Length/height-for-age',
                'wfh' => 'Weight-for-length/height', 'bmi' => 'BMI-for-age'] as $indicator => $label) {
                $details[$label] = $row[$indicator . '_status'] ?? 'Not available';
                $details[$label . ' z-score'] = $row[$indicator . '_z'] ?? 'Not available';
            }
            $details['Implausible values confirmed'] = (int) $row['implausible_confirmed'] === 1 ? 'Yes' : 'No';
            $events[] = ['id' => (int) $row['id'], 'type' => 'Measurement', 'date' => $row['measured_at'],
                'title' => 'Growth measurement', 'details' => $details, 'supplements' => []];
        }
        foreach ($this->immunizations->forChild($childId) as $row) {
            $details = $this->fields($row, ['place_given' => 'Place given', 'lot_number' => 'Lot or batch number',
                'site' => 'Site', 'recorded_by' => 'Given by']);
            $details['Given elsewhere'] = (int) $row['given_elsewhere'] === 1 ? 'Yes' : 'No';
            $events[] = ['id' => (int) $row['id'], 'type' => 'Vaccination', 'date' => $row['date_given'],
                'title' => $row['vaccine_name'] . ' — dose ' . $row['dose_number'], 'details' => $details, 'supplements' => []];
        }
        $supplements = [];
        foreach ($this->supplements->forChild($childId) as $row) {
            $supplements[$row['visit_id']][] = ['name' => VisitValidator::SUPPLEMENTS[$row['type']] ?? 'Other',
                'dose' => $row['dose'], 'date' => $row['date_given']];
        }
        foreach ($this->visits->forChild($childId) as $row) {
            $details = $this->fields($row, ['findings' => 'Findings', 'actions' => 'Actions or treatment',
                'referred_to' => 'Referral destination', 'attended_by_name' => 'Attended by', 'notes' => 'Notes']);
            $details['Referred'] = (int) $row['referred'] === 1 ? 'Yes' : 'No';
            $events[] = ['id' => (int) $row['id'], 'type' => 'Visit', 'date' => $row['visit_date'],
                'title' => $row['reason'], 'details' => $details, 'supplements' => $supplements[$row['id']] ?? []];
        }
        // Date-only records cannot establish order within a day; type and ID keep pagination stable.
        usort($events, static fn (array $a, array $b): int => strcmp($b['date'], $a['date'])
            ?: strcmp($a['type'], $b['type']) ?: $b['id'] <=> $a['id']);
        $total = count($events);
        $pages = max(1, (int) ceil($total / 25));
        $current = min((int) $page, $pages);

        return ['events' => array_slice($events, ($current - 1) * 25, 25), 'total' => $total,
            'page' => $current, 'pages' => $pages, 'from' => $total === 0 ? 0 : ($current - 1) * 25 + 1,
            'to' => min($current * 25, $total)];
    }

    private function fields(array $row, array $labels): array
    {
        $details = [];
        foreach ($labels as $field => $label) {
            $details[$label] = $row[$field] ?? 'Not recorded';
        }

        return $details;
    }
}
