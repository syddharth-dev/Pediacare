<?php

declare(strict_types=1);

use Pediacare\Repositories\RecordCounterRepository;
use Pediacare\Support\RecordNumberGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecordNumberGeneratorTest extends TestCase
{
    #[DataProvider('sequences')]
    public function test_AC05_formats_five_digit_sequence_and_saves_increment(int $previous, string $expected): void
    {
        $counters = $this->createMock(RecordCounterRepository::class);
        $counters->expects(self::once())->method('lock')->with(2026)->willReturn($previous);
        $counters->expects(self::once())->method('save')->with(2026, $previous + 1);
        self::assertSame($expected, (new RecordNumberGenerator($counters))->generate(2026));
    }

    public static function sequences(): array
    {
        return [[0, 'PC-2026-00001'], [122, 'PC-2026-00123'], [99998, 'PC-2026-99999']];
    }

    public function test_AC05_default_year_uses_application_timezone(): void
    {
        $year = (int) date('Y');
        $counters = $this->createMock(RecordCounterRepository::class);
        $counters->expects(self::once())->method('lock')->with($year)->willReturn(0);
        $counters->expects(self::once())->method('save')->with($year, 1);
        self::assertSame(sprintf('PC-%04d-00001', $year), (new RecordNumberGenerator($counters))->generate());
    }

    #[DataProvider('invalidYears')]
    public function test_AC05_invalid_year_is_rejected_before_database_access(int $year): void
    {
        $counters = $this->createMock(RecordCounterRepository::class);
        $counters->expects(self::never())->method('lock');
        $this->expectException(InvalidArgumentException::class);
        (new RecordNumberGenerator($counters))->generate($year);
    }

    public static function invalidYears(): array
    {
        return [[0], [999], [10000]];
    }

    public function test_AC05_exhausted_sequence_is_not_updated_or_wrapped(): void
    {
        $counters = $this->createMock(RecordCounterRepository::class);
        $counters->method('lock')->willReturn(99999);
        $counters->expects(self::never())->method('save');
        $this->expectException(OverflowException::class);
        (new RecordNumberGenerator($counters))->generate(2026);
    }

    public function test_AC05_database_failure_is_not_swallowed(): void
    {
        $counters = $this->createMock(RecordCounterRepository::class);
        $counters->method('save')->willThrowException(new PDOException('FAKE failure'));
        $this->expectException(PDOException::class);
        (new RecordNumberGenerator($counters))->generate(2026);
    }
}
