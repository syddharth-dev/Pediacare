<?php

declare(strict_types=1);

use Pediacare\Repositories\ImmunizationRepository;
use Pediacare\Repositories\MeasurementRepository;
use Pediacare\Repositories\SupplementRepository;
use Pediacare\Repositories\VisitRepository;
use Pediacare\Services\HealthTimelineService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HealthTimelineServiceTest extends TestCase
{
    private MeasurementRepository $measurements;
    private ImmunizationRepository $immunizations;
    private VisitRepository $visits;
    private SupplementRepository $supplements;
    private HealthTimelineService $service;

    protected function setUp(): void
    {
        $this->measurements = $this->createMock(MeasurementRepository::class);
        $this->immunizations = $this->createMock(ImmunizationRepository::class);
        $this->visits = $this->createMock(VisitRepository::class);
        $this->supplements = $this->createMock(SupplementRepository::class);
        $this->service = new HealthTimelineService($this->measurements, $this->immunizations, $this->visits, $this->supplements);
    }

    public function test_AC03_T33_combines_saved_records_without_recalculating_and_keeps_supplements_on_their_visit(): void
    {
        $this->measurements->expects(self::once())->method('history')->with(9)->willReturn([
            ['id' => 2, 'measured_at' => '2026-01-01', 'position' => 'LYING', 'implausible_confirmed' => 1,
                'weight_kg' => '6.00', 'bmi' => '15.00', 'bmi_z' => '-2.01', 'bmi_status' => 'Wasted']]);
        $this->immunizations->expects(self::once())->method('forChild')->with(9)->willReturn([
            ['id' => 1, 'date_given' => '2026-02-01', 'vaccine_name' => 'FAKE Vaccine', 'dose_number' => 2,
                'given_elsewhere' => 1, 'place_given' => 'FAKE Facility']]);
        $this->visits->expects(self::once())->method('forChild')->with(9)->willReturn([
            ['id' => 2, 'visit_date' => '2026-03-01', 'reason' => 'FAKE Visit', 'referred' => 1,
                'referred_to' => 'FAKE Referral'],
            ['id' => 1, 'visit_date' => '2026-01-01', 'reason' => 'FAKE Prior Visit', 'referred' => 0]]);
        $this->supplements->expects(self::once())->method('forChild')->with(9)->willReturn([
            ['visit_id' => 2, 'type' => 'VITAMIN_A', 'dose' => 'FAKE entered dose', 'date_given' => '2026-02-28'],
            ['visit_id' => 2, 'type' => 'MNP', 'dose' => 'FAKE entered dose 2', 'date_given' => '2026-03-01']]);
        $result = $this->service->forChild(9);
        self::assertSame(4, $result['total']);
        self::assertSame(['Visit', 'Vaccination', 'Measurement', 'Visit'], array_column($result['events'], 'type'));
        self::assertCount(2, $result['events'][0]['supplements']);
        self::assertSame('Vitamin A', $result['events'][0]['supplements'][0]['name']);
        self::assertSame('2026-02-28', $result['events'][0]['supplements'][0]['date']);
        self::assertSame('FAKE Referral', $result['events'][0]['details']['Referral destination']);
        self::assertSame('Yes', $result['events'][1]['details']['Given elsewhere']);
        self::assertSame('FAKE Vaccine — dose 2', $result['events'][1]['title']);
        self::assertSame('15.00', $result['events'][2]['details']['BMI (kg/m²)']);
        self::assertSame('-2.01', $result['events'][2]['details']['BMI-for-age z-score']);
        self::assertSame('Wasted', $result['events'][2]['details']['BMI-for-age']);
        self::assertSame('Yes', $result['events'][2]['details']['Implausible values confirmed']);
        self::assertSame([], $result['events'][3]['supplements']);
        self::assertSame('Not recorded', $result['events'][3]['details']['Notes']);
    }

    public function test_AC03_T33_same_day_records_paginate_in_stable_order_without_duplicates(): void
    {
        $rows = [];
        for ($id = 1; $id <= 51; ++$id) {
            $rows[] = ['id' => $id, 'visit_date' => '2026-01-01', 'reason' => 'FAKE Visit ' . $id, 'referred' => 0];
        }
        $this->visits->method('forChild')->willReturn($rows);
        $found = [];
        foreach (['1', '2', '3'] as $page) {
            $result = $this->service->forChild(9, ['page' => $page]);
            self::assertSame(51, $result['total']);
            self::assertSame(3, $result['pages']);
            $found = [...$found, ...array_column($result['events'], 'id')];
        }
        self::assertSame(range(51, 1), $found);
        $result = $this->service->forChild(9, ['page' => '999']);
        self::assertSame(3, $result['page']);
        self::assertSame(51, $result['from']);
        self::assertSame(51, $result['to']);
        self::assertCount(1, $result['events']);
    }

    public function test_AC03_T33_empty_history_has_zero_range_and_one_page(): void
    {
        $result = $this->service->forChild(9);
        self::assertSame([], $result['events']);
        self::assertSame([0, 0, 0, 1, 1], [$result['total'], $result['from'], $result['to'], $result['page'], $result['pages']]);
    }

    #[DataProvider('invalidPages')]
    public function test_AC03_T33_invalid_page_never_loads_child_health_history(mixed $page): void
    {
        $this->measurements->expects(self::never())->method('history');
        $this->immunizations->expects(self::never())->method('forChild');
        $this->visits->expects(self::never())->method('forChild');
        $this->supplements->expects(self::never())->method('forChild');
        $this->expectException(InvalidArgumentException::class);
        $this->service->forChild(9, ['page' => $page]);
    }

    public static function invalidPages(): array
    {
        return [[''], ['0'], ['-1'], ['01'], ['2147483648'], ['1 OR 1=1'], [[]], [1]];
    }
}
