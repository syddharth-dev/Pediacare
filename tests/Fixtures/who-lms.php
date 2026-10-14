<?php

declare(strict_types=1);

use Pediacare\Domain\Growth\LmsTable;

$rows = [];
foreach (['who2006.csv', 'who2007.csv'] as $file) {
    $stream = fopen(dirname(__DIR__, 2) . '/database/who/' . $file, 'rb');
    $header = fgetcsv($stream);
    while (($values = fgetcsv($stream)) !== false) {
        $rows[] = array_combine($header, $values);
    }
    fclose($stream);
}

return new LmsTable($rows);
