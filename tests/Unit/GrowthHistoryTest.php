<?php

declare(strict_types=1);

use Pediacare\Domain\Enums\Sex;
use Pediacare\Domain\Growth\BmiCurves;
use Pediacare\Domain\Growth\LmsParameters;
use Pediacare\Domain\Growth\LmsTable;
use Pediacare\Domain\Growth\LmsZScore;
use Pediacare\Repositories\MeasurementRepository;
use Pediacare\Repositories\WhoLmsRepository;
use Pediacare\Services\GrowthHistoryService;
use Pediacare\Core\View;
use PHPUnit\Framework\TestCase;

final class GrowthHistoryTest extends TestCase
{
    private static LmsTable $table;

    public static function setUpBeforeClass(): void
    {
        self::$table = require dirname(__DIR__) . '/Fixtures/who-lms.php';
    }

    private function row(array $changes = []): array
    {
        return array_replace(['id' => 1, 'child_id' => 9, 'measured_at' => '2001-01-01',
            'age_months' => 12, 'weight_kg' => '9.50', 'height_cm' => '75.4', 'muac_cm' => null,
            'position' => 'LYING', 'bmi' => '16.71', 'wfa_z' => '-0.14', 'hfa_z' => '-0.09',
            'wfh_z' => '-0.15', 'bmi_z' => '-0.14', 'wfa_status' => 'Normal', 'hfa_status' => 'Normal',
            'wfh_status' => 'Normal', 'bmi_status' => 'Normal', 'implausible_confirmed' => 0,
            'measured_by_name' => '<script>FAKE staff</script>'], $changes);
    }

    public function test_AC06_inverse_LMS_handles_median_negative_L_and_log_limit(): void
    {
        $parameters = new LmsParameters(-1, 16, 0.1);
        self::assertEqualsWithDelta(20, LmsZScore::valueAt(2, $parameters), 1e-10);
        self::assertEqualsWithDelta(16 / 1.2, LmsZScore::valueAt(-2, $parameters), 1e-10);
        self::assertSame(16.0, LmsZScore::valueAt(0, $parameters));
        self::assertEqualsWithDelta(16 * exp(0.2), LmsZScore::valueAt(2, new LmsParameters(0, 16, 0.1)), 1e-10);
    }

    public function test_AC06_WHO_curves_keep_reference_and_length_height_boundaries_separate_for_both_sexes(): void
    {
        foreach (Sex::cases() as $sex) {
            $curves = BmiCurves::points(self::$table, $sex, 6970 / 30.4375);
            self::assertCount(18, $curves);
            foreach ($curves as $index => $curve) {
                $first = $curve['data'][0];
                $last = $curve['data'][count($curve['data']) - 1];
                if ($index < 6) {
                    self::assertSame(0.0, $first['x']);
                    self::assertSame(730 / 30.4375, $last['x']);
                } elseif ($index < 12) {
                    self::assertSame(731 / 30.4375, $first['x']);
                    self::assertSame(1856 / 30.4375, $last['x']);
                } else {
                    self::assertSame(61.0, $first['x']);
                    self::assertLessThan(229, $last['x']);
                }
                $coordinate = $curve['reference'] === 'WHO2006' ? $last['x'] * 30.4375 : $last['x'];
                $parameters = self::$table->at($curve['reference'], 'BFA', $sex, $coordinate);
                self::assertEqualsWithDelta($curve['z'], LmsZScore::calculate($last['y'], $parameters, true), 1e-10);
            }
        }
        self::assertCount(6, BmiCurves::points(self::$table, Sex::MALE, 6));
    }

    public function test_AC06_history_preserves_saved_results_and_chart_uses_exact_age_and_current_sex(): void
    {
        $rows = [$this->row(['measured_at' => '2005-02-01', 'id' => 2, 'bmi' => '15.67', 'implausible_confirmed' => 1]), $this->row()];
        $measurements = $this->createMock(MeasurementRepository::class);
        $measurements->expects(self::once())->method('history')->with(9)->willReturn($rows);
        $references = $this->createMock(WhoLmsRepository::class);
        $references->expects(self::once())->method('load')->willReturn(self::$table);
        $growth = (new GrowthHistoryService($measurements, $references))->forChild(
            ['id' => 9, 'date_of_birth' => '2000-01-01', 'sex' => 'F']
        );
        self::assertSame($rows, $growth['history']);
        $chart = json_decode($growth['chartJson'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2001-01-01', $chart['points'][0]['date']);
        self::assertSame(366 / 30.4375, $chart['points'][0]['x']);
        self::assertSame(16.71, $chart['points'][0]['y']);
        self::assertTrue($chart['points'][1]['confirmed']);
        self::assertSame(self::$table->at('WHO2006', 'BFA', Sex::FEMALE, 0)->m, $chart['curves'][2]['data'][0]['y']);
        self::assertStringNotContainsString('FAKE staff', $growth['chartJson']);
        $html = (new View(dirname(__DIR__, 2) . '/views'))->render('children/growth', [
            'growth' => $growth, 'child' => ['sex' => 'F'],
            'growthIndicators' => ['wfa' => 'Weight-for-age', 'hfa' => 'Height-for-age', 'wfh' => 'Weight-for-height', 'bmi' => 'BMI-for-age'],
        ], null);
        self::assertStringContainsString('&lt;script&gt;FAKE staff&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>FAKE', $html);
        self::assertStringContainsString('Implausible values confirmed', $html);
        self::assertStringContainsString('Not recorded', $html);
        self::assertStringContainsString('scope="col"', $html);
        self::assertStringContainsString('tabindex="0"', $html);
        self::assertStringContainsString('/assets/vendor/chart.umd.min.js', $html);
        self::assertStringContainsString('The chart needs JavaScript', $html);
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $source = (new DOMXPath($document))->query('//*[@id="growth-chart-data"]')->item(0);
        self::assertSame($chart, json_decode($source->getAttribute('data-chart'), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_AC06_corrected_birth_date_outside_chart_coverage_keeps_history_without_loading_curves(): void
    {
        $measurements = $this->createMock(MeasurementRepository::class);
        $measurements->method('history')->willReturn([$this->row(), $this->row(['measured_at' => '2026-01-01'])]);
        $references = $this->createMock(WhoLmsRepository::class);
        $references->expects(self::never())->method('load');
        $growth = (new GrowthHistoryService($measurements, $references))->forChild(
            ['id' => 9, 'date_of_birth' => '2002-01-01', 'sex' => 'M']
        );
        self::assertSame(2, $growth['omitted']);
        self::assertCount(2, $growth['history']);
        self::assertNull($growth['chartJson']);
    }

    public function test_AC06_empty_history_does_not_require_WHO_seed_or_show_empty_canvas(): void
    {
        $measurements = $this->createMock(MeasurementRepository::class);
        $measurements->method('history')->willReturn([]);
        $references = $this->createMock(WhoLmsRepository::class);
        $references->expects(self::never())->method('load');
        $growth = (new GrowthHistoryService($measurements, $references))->forChild(['id' => 9]);
        $html = (new View(dirname(__DIR__, 2) . '/views'))->render('children/growth', ['growth' => $growth], null);
        self::assertStringContainsString('No measurements recorded yet.', $html);
        self::assertStringNotContainsString('<canvas', $html);
    }
}
