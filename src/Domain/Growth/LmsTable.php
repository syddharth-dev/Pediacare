<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth;

use InvalidArgumentException;
use LogicException;
use Pediacare\Domain\Enums\Sex;

final class LmsTable
{
    /** @var array<string, list<array{coordinate: float, parameters: LmsParameters}>> */
    private readonly array $groups;

    /** @param list<array{reference: string, indicator: string, sex: string, age_or_size: string|float|int, l: string|float|int, m: string|float|int, s: string|float|int}> $rows */
    public function __construct(array $rows)
    {
        $groups = [];
        foreach ($rows as $row) {
            if (!in_array($row['reference'], ['WHO2006', 'WHO2007'], true)
                || !in_array($row['indicator'], ['WFA', 'HFA', 'WFL', 'WFH', 'BFA'], true)
                || Sex::tryFrom($row['sex']) === null) {
                throw new InvalidArgumentException('Invalid LMS identity.');
            }
            foreach (['age_or_size', 'l', 'm', 's'] as $field) {
                if (!is_numeric($row[$field]) || !is_finite((float) $row[$field])) {
                    throw new InvalidArgumentException('Invalid LMS data.');
                }
            }
            $coordinate = (float) $row['age_or_size'];
            if ($coordinate < 0) {
                throw new InvalidArgumentException('Invalid LMS coordinate.');
            }
            $key = $row['reference'] . ':' . $row['indicator'] . ':' . $row['sex'];
            $index = (string) $coordinate;
            if (isset($groups[$key][$index])) {
                throw new InvalidArgumentException('Duplicate LMS coordinate.');
            }
            $groups[$key][$index] = [
                'coordinate' => $coordinate,
                'parameters' => new LmsParameters((float) $row['l'], (float) $row['m'], (float) $row['s']),
            ];
        }
        foreach ($groups as &$group) {
            $group = array_values($group);
            usort($group, static fn (array $a, array $b): int => $a['coordinate'] <=> $b['coordinate']);
        }
        unset($group);
        $this->groups = $groups;
    }

    public function at(string $reference, string $indicator, Sex $sex, float $coordinate): ?LmsParameters
    {
        if (!is_finite($coordinate) || $coordinate < 0) {
            throw new InvalidArgumentException('Invalid LMS coordinate.');
        }
        $group = $this->groups[$reference . ':' . $indicator . ':' . $sex->value] ?? null;
        if ($group === null) {
            throw new LogicException('Required WHO reference data is missing.');
        }
        $low = 0;
        $high = count($group) - 1;
        if ($coordinate < $group[$low]['coordinate'] || $coordinate > $group[$high]['coordinate']) {
            return null;
        }
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $value = $group[$middle]['coordinate'];
            if ($value === $coordinate) {
                return $group[$middle]['parameters'];
            }
            if ($value < $coordinate) {
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }
        $a = $group[$high];
        $b = $group[$low];
        $fraction = ($coordinate - $a['coordinate']) / ($b['coordinate'] - $a['coordinate']);
        // WHO AnthroPlus manual §3.2.1: interpolate LMS between adjacent reference coordinates.
        // https://www.who.int/docs/default-source/child-growth/growth-reference-5-19-years/who-anthroplus-manual.pdf
        return new LmsParameters(
            $a['parameters']->l + $fraction * ($b['parameters']->l - $a['parameters']->l),
            $a['parameters']->m + $fraction * ($b['parameters']->m - $a['parameters']->m),
            $a['parameters']->s + $fraction * ($b['parameters']->s - $a['parameters']->s),
        );
    }
}
