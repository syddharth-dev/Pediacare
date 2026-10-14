<?php

declare(strict_types=1);

use Pediacare\Support\ReportOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportOutputTest extends TestCase
{
    #[DataProvider('cellValues')]
    public function test_AC13_csv_preserves_quoted_utf8_text_and_protects_formula_cells(string $value, string $expected): void
    {
        $response = ReportOutput::csv('defaulters', ['children' => [[
            'record_no' => 'PC-2090-00001', 'full_name' => $value, 'purok_name' => 'FAKE Purok',
            'overdue' => ['FAKE Vaccine — dose 1', 'FAKE Vaccine — dose 2'],
        ]]]);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $response->body);
        rewind($stream);
        self::assertSame(['Record number', 'Child', 'Purok or sitio', 'Overdue doses'], fgetcsv($stream, 0, ',', '"', ''));
        $row = fgetcsv($stream, 0, ',', '"', '');
        self::assertSame($expected, $row[1]);
        self::assertSame('FAKE Vaccine — dose 1; FAKE Vaccine — dose 2', $row[3]);
        self::assertFalse(fgetcsv($stream, 0, ',', '"', ''));
        fclose($stream);
        self::assertSame('text/csv; charset=UTF-8', $response->headers['Content-Type']);
        self::assertSame('attachment; filename="pediacare-defaulters.csv"', $response->headers['Content-Disposition']);
        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    public static function cellValues(): array
    {
        return [
            ['FAKE Niño, "Family"\\name', 'FAKE Niño, "Family"\\name'],
            ["FAKE\nMultiline", "FAKE\nMultiline"],
            ['=1+1', "'=1+1"], ['+1+1', "'+1+1"], ['-1+1', "'-1+1"], ['@SUM(1)', "'@SUM(1)"],
            ['  =1+1', "'  =1+1"], ["\tFAKE", "'\tFAKE"], ["\rFAKE", "'\rFAKE"], ["\nFAKE", "'\nFAKE"],
        ];
    }

    public function test_AC13_download_links_keep_validated_filters_and_remove_page(): void
    {
        self::assertSame([
            'csvUrl' => '/reports/malnutrition?include_archived=1&format=csv',
            'printUrl' => '/reports/malnutrition?include_archived=1&format=print',
        ], ReportOutput::links('/reports/malnutrition', ['page' => '7', 'include_archived' => '1']));
    }
}
