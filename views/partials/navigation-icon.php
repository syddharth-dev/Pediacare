<?php

declare(strict_types=1);

$iconPaths = [
    '/children' => 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 9v-2a7 7 0 0 1 14 0v2m1-16a4 4 0 0 1 0 7m3 9v-2a6 6 0 0 0-3-5',
    '/children/new' => 'M5 3h10l4 4v14H5V3Zm10 0v5h4M8 13h8m-4-4v8',
    '/immunizations/defaulters' => 'M12 3 2 21h20L12 3Zm0 6v5m0 3v1',
    '/reports/malnutrition' => 'M3 12h5l3-8 4 16 3-8h3',
    '/admin/users' => 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-8 9v-2a8 8 0 0 1 16 0v2',
    '/admin/puroks' => 'm3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Zm6-3v15m6-12v15',
    '/admin/schedule' => 'M4 5h16v16H4V5Zm3-3v6m10-6v6M4 11h16m-12 4h2m4 0h2m-8 3h2',
    '/admin/audit' => 'M8 3H5v18h14V3h-3M8 2h8v4H8V2Zm0 8h8m-8 4h8m-8 4h5',
    '/reports/nutrition' => 'M4 3v18h17M8 16v-5m5 5V7m5 9v-7',
    '/reports/immunization' => 'M4 3v18h17M7 15l4-5 4 3 5-7',
];

?>
<svg class="ui-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="<?= e($iconPaths[$href] ?? 'M3 3h7v7H3V3Zm11 0h7v7h-7V3ZM3 14h7v7H3v-7Zm11 0h7v7h-7v-7Z') ?>"/></svg>
