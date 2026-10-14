<?php

declare(strict_types=1);

namespace Pediacare\Services;

use InvalidArgumentException;
use Pediacare\Repositories\ReportRepository;
use Pediacare\Support\Permissions;
use Pediacare\Validation\ReportFilterValidator;

final class ReportService
{
    // Category labels follow plan.md §6.3; reports count saved labels, never reclassify measurements.
    public const INDICATORS = [
        'wfa' => ['label' => 'Weight-for-age', 'categories' => ['Severely underweight', 'Underweight', 'Normal', 'High weight-for-age']],
        'hfa' => ['label' => 'Length/height-for-age', 'categories' => ['Severely stunted', 'Stunted', 'Normal', 'Tall']],
        'wfh' => ['label' => 'Weight-for-length/height', 'categories' => ['Severely wasted', 'Wasted', 'Normal', 'Overweight', 'Obese']],
        'bmi' => ['label' => 'BMI-for-age', 'categories' => ['Severely wasted', 'Wasted', 'Normal', 'Overweight', 'Obese']],
    ];

    public function __construct(private readonly ReportRepository $reports, private readonly ReportFilterValidator $validator)
    {
    }

    public function malnutrition(array $input, string $role, bool $all = false): array
    {
        if (!Permissions::allows($role, 'children.read')) {
            throw new InvalidArgumentException('Individual child report access is not allowed.');
        }
        $filters = $this->validator->listing($input);
        $total = $this->reports->malnutritionCount($filters);
        $limit = $all ? max(1, $total) : 25;
        $pages = max(1, (int) ceil($total / $limit));
        $page = min((int) $filters['page'], $pages);
        $filters['page'] = (string) $page;
        $offset = ($page - 1) * $limit;
        $children = $this->reports->malnutritionPage($filters, $limit, $offset);
        foreach ($children as &$child) {
            $child['full_name'] = implode(' ', array_filter([
                $child['first_name'], $child['middle_name'], $child['last_name'], $child['suffix'],
            ], static fn (?string $part): bool => $part !== null && $part !== ''));
        }
        unset($child);

        return ['children' => $children, 'filters' => $filters, 'total' => $total, 'page' => $page,
            'pages' => $pages, 'from' => $total === 0 ? 0 : $offset + 1, 'to' => $offset + count($children)];
    }

    public function nutritional(array $input, string $role): array
    {
        if (!Permissions::allows($role, 'reports.aggregate')) {
            throw new InvalidArgumentException('Report access is not allowed.');
        }
        $filters = $this->validator->validate($input);
        $byPurok = $role !== 'VIEWER';
        $groups = ['barangay' => $this->emptyGroup('Barangay total')];
        foreach ($this->reports->nutritionalCounts($filters, $byPurok) as $row) {
            $keys = ['barangay'];
            if ($byPurok) {
                $key = 'purok-' . $row['purok_id'];
                $groups[$key] ??= $this->emptyGroup($row['purok_name']);
                $keys[] = $key;
            }
            foreach ($keys as $key) {
                $count = (int) $row['child_count'];
                $sex = $row['sex'];
                $groups[$key]['totals'][$sex] += $count;
                $groups[$key]['totals']['total'] += $count;
                foreach (self::INDICATORS as $indicator => $definition) {
                    $status = $row[$indicator . '_status'] ?? 'Not available';
                    $groups[$key]['indicators'][$indicator][$status] ??= ['M' => 0, 'F' => 0, 'total' => 0];
                    $groups[$key]['indicators'][$indicator][$status][$sex] += $count;
                    $groups[$key]['indicators'][$indicator][$status]['total'] += $count;
                }
            }
        }

        return ['filters' => $filters, 'groups' => array_values($groups), 'byPurok' => $byPurok];
    }

    public function immunization(array $input, string $role): array
    {
        if (!Permissions::allows($role, 'reports.aggregate')) {
            throw new InvalidArgumentException('Report access is not allowed.');
        }
        $filters = $this->validator->monthly($input);
        $vaccines = [];
        $totals = ['M' => 0, 'F' => 0, 'total' => 0];
        foreach ($this->reports->monthlyDoses($filters) as $row) {
            $counts = ['M' => (int) $row['male_count'], 'F' => (int) $row['female_count']];
            $counts['total'] = $counts['M'] + $counts['F'];
            $vaccines[] = ['name' => $row['vaccine_name'], 'counts' => $counts];
            foreach ($totals as $key => $value) {
                $totals[$key] += $counts[$key];
            }
        }
        $fic = $this->reports->monthlyFic($filters);
        $ficCounts = ['M' => (int) $fic['male_count'], 'F' => (int) $fic['female_count']];
        $ficCounts['total'] = $ficCounts['M'] + $ficCounts['F'];

        return ['filters' => $filters, 'vaccines' => $vaccines, 'totals' => $totals, 'fic' => $ficCounts];
    }

    private function emptyGroup(string $name): array
    {
        $group = ['name' => $name, 'totals' => ['M' => 0, 'F' => 0, 'total' => 0], 'indicators' => []];
        foreach (self::INDICATORS as $indicator => $definition) {
            foreach ([...$definition['categories'], 'Not available'] as $category) {
                $group['indicators'][$indicator][$category] = ['M' => 0, 'F' => 0, 'total' => 0];
            }
        }

        return $group;
    }
}
