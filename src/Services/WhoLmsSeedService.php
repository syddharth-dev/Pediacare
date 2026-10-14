<?php

declare(strict_types=1);

namespace Pediacare\Services;

use LogicException;
use PDO;
use Pediacare\Repositories\WhoLmsRepository;
use RuntimeException;
use Throwable;

final class WhoLmsSeedService
{
    private const COLUMNS = ['reference', 'indicator', 'sex', 'age_or_size', 'l', 'm', 's'];
    private const FILES = ['who2006.csv' => 'WHO2006', 'who2007.csv' => 'WHO2007'];

    public function __construct(private readonly PDO $pdo, private readonly WhoLmsRepository $references)
    {
    }

    public function run(string $directory): int
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('WHO reference imports must own their transaction.');
        }

        $manifest = json_decode($this->read($directory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || array_keys($manifest) !== array_keys(self::FILES)) {
            throw new RuntimeException('WHO reference manifest is invalid.');
        }

        $rows = [];
        $keys = [];

        foreach (self::FILES as $filename => $reference) {
            $content = $this->read($directory . '/' . $filename);
            $entry = $manifest[$filename];
            if (!is_array($entry) || !is_int($entry['rows'] ?? null) || $entry['rows'] < 1
                || !is_string($entry['sha256'] ?? null)
                || !hash_equals($entry['sha256'], hash('sha256', $content))
            ) {
                throw new RuntimeException('WHO reference file integrity check failed.');
            }

            $lines = explode("\n", rtrim($content, "\n"));
            $header = str_getcsv(rtrim(array_shift($lines), "\r"), ',', '"', '');
            if ($header !== self::COLUMNS || count($lines) !== $entry['rows']) {
                throw new RuntimeException('WHO reference header or row count is invalid.');
            }

            foreach ($lines as $line) {
                $values = str_getcsv(rtrim($line, "\r"), ',', '"', '');
                if (count($values) !== count(self::COLUMNS)) {
                    throw new RuntimeException('WHO reference row is invalid.');
                }
                $row = array_combine(self::COLUMNS, $values);
                $this->validate($row, $reference);
                $key = $reference . ':' . $row['indicator'] . ':' . $row['sex'] . ':'
                    . number_format((float) $row['age_or_size'], 2, '.', '');
                if (isset($keys[$key])) {
                    throw new RuntimeException('WHO reference coordinates must be unique.');
                }
                $keys[$key] = true;
                $rows[] = $row;
            }
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $this->references->upsert($row);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return count($rows);
    }

    private function read(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('WHO reference file is missing or unreadable.');
        }
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            throw new RuntimeException('WHO reference file is empty or unreadable.');
        }

        return $content;
    }

    private function validate(array $row, string $reference): void
    {
        $indicators = $reference === 'WHO2006' ? ['WFA', 'HFA', 'WFL', 'WFH', 'BFA'] : ['WFA', 'HFA', 'BFA'];
        if ($row['reference'] !== $reference || !in_array($row['indicator'], $indicators, true)
            || !in_array($row['sex'], ['M', 'F'], true)
            || !is_string($row['age_or_size'])
            || preg_match('/\A(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,2})?\z/', $row['age_or_size']) !== 1
        ) {
            throw new RuntimeException('WHO reference identity or coordinate is invalid.');
        }
        foreach (['l', 'm', 's'] as $parameter) {
            $value = $row[$parameter];
            // Match DECIMAL(10,6) without rounding or accepting exponent notation.
            if (!is_string($value) || preg_match('/\A-?(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,6})?\z/', $value) !== 1
                || ($parameter !== 'l' && (float) $value <= 0)
            ) {
                throw new RuntimeException('WHO LMS parameter is invalid.');
            }
        }
    }
}
