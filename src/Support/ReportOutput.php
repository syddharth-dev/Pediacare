<?php

declare(strict_types=1);

namespace Pediacare\Support;

use InvalidArgumentException;
use RuntimeException;
use Pediacare\Core\Response;
use Pediacare\Services\ReportService;

final class ReportOutput
{
    public static function format(array $query): string
    {
        $format = $query['format'] ?? 'html';
        if (!in_array($format, ['html', 'csv', 'print'], true)) {
            throw new InvalidArgumentException('Choose HTML, CSV or print output.');
        }

        return $format;
    }

    public static function links(string $path, array $filters): array
    {
        unset($filters['page']);
        $url = static fn (string $format): string => $path . '?' . http_build_query(
            $filters + ['format' => $format], '', '&', PHP_QUERY_RFC3986);

        return ['csvUrl' => $url('csv'), 'printUrl' => $url('print')];
    }

    public static function csv(string $report, array $results): Response
    {
        $rows = match ($report) {
            'nutrition' => self::nutritionRows($results),
            'immunization' => self::immunizationRows($results),
            'malnutrition' => self::malnutritionRows($results),
            'defaulters' => self::defaulterRows($results),
            default => throw new InvalidArgumentException('Unknown report.'),
        };
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to prepare report download.');
        }
        try {
            foreach ($rows as $row) {
                // Treat formula-leading text as literal spreadsheet cells, including leading whitespace.
                $safe = array_map(static fn (string|int $value): string|int => is_string($value)
                    && preg_match('/\A(?:[\t\r\n]|\s*[=+\-@])/u', $value) === 1 ? "'" . $value : $value, $row);
                if (fputcsv($stream, $safe, ',', '"', '', "\r\n") === false) {
                    throw new RuntimeException('Unable to prepare report download.');
                }
            }
            rewind($stream);
            $body = stream_get_contents($stream);
            if ($body === false) {
                throw new RuntimeException('Unable to prepare report download.');
            }
        } finally {
            fclose($stream);
        }

        return new Response($body, 200, ['Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="pediacare-' . $report . '.csv"', 'Cache-Control' => 'no-store']);
    }

    private static function nutritionRows(array $results): array
    {
        $rows = [['Start date', 'End date', 'Include archived', 'Group', 'Indicator', 'Status', 'Male', 'Female', 'Total']];
        foreach ($results['groups'] as $group) {
            foreach (ReportService::INDICATORS as $key => $indicator) {
                foreach ($group['indicators'][$key] + ['Total' => $group['totals']] as $status => $counts) {
                    $rows[] = [$results['filters']['from'], $results['filters']['to'], $results['filters']['include_archived'],
                        $group['name'], $indicator['label'], $status, $counts['M'], $counts['F'], $counts['total']];
                }
            }
        }

        return $rows;
    }

    private static function immunizationRows(array $results): array
    {
        $rows = [['Start date', 'End date', 'Include archived', 'Measure', 'Male', 'Female', 'Total']];
        $items = [...$results['vaccines'], ['name' => 'Total doses', 'counts' => $results['totals']],
            ['name' => 'Children completing FIC this month', 'counts' => $results['fic']]];
        foreach ($items as $item) {
            $rows[] = [$results['filters']['from'], $results['filters']['to'], $results['filters']['include_archived'],
                $item['name'], $item['counts']['M'], $item['counts']['F'], $item['counts']['total']];
        }

        return $rows;
    }

    private static function malnutritionRows(array $results): array
    {
        $rows = [['Record number', 'Child', 'Sex', 'Purok or sitio', 'Measured on', 'Weight-for-age',
            'Length/height-for-age', 'Weight-for-length/height', 'BMI-for-age', 'Archived']];
        foreach ($results['children'] as $child) {
            $rows[] = [$child['record_no'], $child['full_name'], $child['sex'] === 'M' ? 'Male' : 'Female',
                $child['purok_name'], $child['measured_at'], $child['wfa_status'] ?? 'Not available',
                $child['hfa_status'] ?? 'Not available', $child['wfh_status'] ?? 'Not available',
                $child['bmi_status'] ?? 'Not available', $child['archived_at'] === null ? 'No' : 'Yes'];
        }

        return $rows;
    }

    private static function defaulterRows(array $results): array
    {
        $rows = [['Record number', 'Child', 'Purok or sitio', 'Overdue doses']];
        foreach ($results['children'] as $child) {
            $rows[] = [$child['record_no'], $child['full_name'], $child['purok_name'], implode('; ', $child['overdue'])];
        }

        return $rows;
    }
}
