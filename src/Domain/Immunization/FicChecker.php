<?php

declare(strict_types=1);

namespace Pediacare\Domain\Immunization;

use InvalidArgumentException;
use Pediacare\Domain\Enums\DoseStatus;

final class FicChecker
{
    /**
     * @param list<array{id: int, counts_toward_fic: int}> $scheduleDoses
     * @param array<int, DoseStatus> $statuses Results from DoseStatusCalculator.
     */
    public function isFullyImmunized(array $scheduleDoses, array $statuses): bool
    {
        $required = [];
        $seen = [];
        foreach ($scheduleDoses as $dose) {
            if (!is_array($dose) || !isset($dose['id']) || !is_int($dose['id']) || $dose['id'] < 1
                || !in_array($dose['counts_toward_fic'] ?? null, [0, 1], true) || isset($seen[$dose['id']])) {
                throw new InvalidArgumentException('Invalid FIC schedule configuration.');
            }
            $seen[$dose['id']] = true;
            if ($dose['counts_toward_fic'] === 1) {
                $required[] = $dose['id'];
            }
        }
        // Source: spec.md AC-08 and plan.md §6.4; empty configuration never certifies FIC.
        if ($required === []) {
            return false;
        }
        foreach ($required as $id) {
            if (($statuses[$id] ?? null) !== DoseStatus::GIVEN) {
                return false;
            }
        }

        return true;
    }
}
