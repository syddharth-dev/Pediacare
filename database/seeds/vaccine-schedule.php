<?php

declare(strict_types=1);

// DRAFT: approved project seed, plan.md §6.4; confirm with the center before live use.
// WHO routine series intervals (preferred bOPV + IPV), December 2025:
// https://cdn.who.int/media/docs/default-source/immunization/immunization_schedules/immunization-summary-table-2.pdf
// FIC membership: DOH January 2025 description, linked in plan.md §6.4.
// Ages below are [age days, age calendar months, interval days, interval calendar months, FIC].
// Zero grace is an editable software default, not a clinical rule.
return [
    ['code' => 'BCG', 'name' => 'BCG', 'doses' => [[0, null, 0, null, 1]]],
    ['code' => 'HEPB_BIRTH', 'name' => 'Hepatitis B (birth dose, within 24 hours)', 'doses' => [[0, null, 0, null, 0]]],
    ['code' => 'PENTA', 'name' => 'Pentavalent (DPT-HepB-Hib)', 'doses' => [
        [42, null, 0, null, 1], [70, null, 28, null, 1], [98, null, 28, null, 1],
    ]],
    ['code' => 'BOPV', 'name' => 'Oral polio vaccine (bOPV)', 'doses' => [
        [42, null, 0, null, 1], [70, null, 28, null, 1], [98, null, 28, null, 1],
    ]],
    ['code' => 'IPV', 'name' => 'Inactivated polio vaccine (IPV)', 'doses' => [
        [98, null, 0, null, 0], [null, 9, null, 4, 0],
    ]],
    ['code' => 'PCV', 'name' => 'Pneumococcal conjugate vaccine (PCV)', 'doses' => [
        [42, null, 0, null, 0], [70, null, 28, null, 0], [98, null, 28, null, 0],
    ]],
    ['code' => 'MCV', 'name' => 'Measles-containing vaccine (MMR/MR)', 'doses' => [
        [null, 9, 0, null, 1], [null, 12, 28, null, 1],
    ]],
];
