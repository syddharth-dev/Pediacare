<?php

declare(strict_types=1);

namespace Pediacare\Support;

use InvalidArgumentException;
use OverflowException;
use Pediacare\Repositories\RecordCounterRepository;

final class RecordNumberGenerator
{
    public function __construct(private readonly RecordCounterRepository $counters)
    {
    }

    public function generate(?int $year = null): string
    {
        $year ??= (int) date('Y');

        if ($year < 1000 || $year > 9999) {
            throw new InvalidArgumentException('Record year must have four digits.');
        }

        $lastNumber = $this->counters->lock($year);
        if ($lastNumber >= 99999) {
            throw new OverflowException('The five-digit record sequence for this year is exhausted.');
        }

        $number = $lastNumber + 1;
        $this->counters->save($year, $number);

        return sprintf('PC-%04d-%05d', $year, $number);
    }
}
