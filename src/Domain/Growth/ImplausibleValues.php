<?php

declare(strict_types=1);

namespace Pediacare\Domain\Growth;

use InvalidArgumentException;

final class ImplausibleValues
{
    /** @return list<string> */
    public static function indicators(GrowthResult $result): array
    {
        // plan.md §6.3; WHO AnthroPlus manual §3.4.2 (WFA/HFA/BFA), Anthro manual §3.4.2 (WFH).
        // https://www.who.int/docs/default-source/child-growth/growth-reference-5-19-years/who-anthroplus-manual.pdf
        // https://cdn.who.int/media/docs/default-source/child-growth/child-growth-standards/software/anthro-pc-manual-v322.pdf
        $values = [
            'wfa_z' => [$result->wfaZ, -6, 5],
            'hfa_z' => [$result->hfaZ, -6, 6],
            'wfh_z' => [$result->wfhZ, -5, 5],
            'bmi_z' => [$result->bmiZ, -5, 5],
        ];
        $flagged = [];
        foreach ($values as $indicator => [$z, $lower, $upper]) {
            if ($z === null) {
                continue;
            }
            if (!is_finite($z)) {
                throw new InvalidArgumentException('Z-score must be finite or unavailable.');
            }
            if ($z < $lower || $z > $upper) {
                $flagged[] = $indicator;
            }
        }

        return $flagged;
    }
}
