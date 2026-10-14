<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth;

use LogicException;
use Pediacare\Domain\Enums\Sex;

final class BmiCurves
{
    public static function points(LmsTable $table, Sex $sex, float $maximumMonths): array
    {
        $curves = [];
        // Published age coverage and length/height switch: database/who/README.md.
        // Separate segments preserve the day-731 switch and WHO2006/WHO2007 boundary.
        foreach ([['WHO2006', 0, 730, 30], ['WHO2006', 731, 1856, 30],
            ['WHO2007', 61, 229, 1]] as [$reference, $first, $last, $step]) {
            $end = min((float) $last, $reference === 'WHO2006' ? $maximumMonths * 30.4375 : $maximumMonths);
            if ($end < $first) {
                continue;
            }
            $coordinates = range($first, (int) floor($end), $step);
            $coordinates[] = $end;
            $coordinates = array_unique($coordinates, SORT_NUMERIC);
            foreach ([-3, -2, 0, 1, 2, 3] as $z) {
                $points = [];
                foreach ($coordinates as $coordinate) {
                    $parameters = $table->at($reference, 'BFA', $sex, (float) $coordinate);
                    if ($parameters === null) {
                        throw new LogicException('Required WHO chart parameters are missing.');
                    }
                    // Inverse LMS: WHO computation guide, p. 2 (also used for restricted tails).
                    // https://www.who.int/docs/default-source/child-growth/growth-reference-5-19-years/computation.pdf
                    $points[] = ['x' => $reference === 'WHO2006' ? $coordinate / 30.4375 : (float) $coordinate,
                        'y' => LmsZScore::valueAt($z, $parameters)];
                }
                $curves[] = ['reference' => $reference, 'z' => $z, 'data' => $points];
            }
        }

        return $curves;
    }
}
