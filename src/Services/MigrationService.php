<?php

declare(strict_types=1);

namespace Pediacare\Services;

use Pediacare\Repositories\MigrationRepository;
use RuntimeException;

final class MigrationService
{
    public function __construct(private readonly MigrationRepository $migrations)
    {
    }

    /** @return list<string> */
    public function run(string $directory): array
    {
        $root = realpath($directory);
        $files = glob($directory . '/*.sql');

        if ($root === false || $files === false || $files === []) {
            throw new RuntimeException('Migration directory or tracking migration is missing.');
        }

        sort($files, SORT_STRING);
        $numbers = [];

        foreach ($files as $file) {
            $filename = basename($file);
            $resolved = realpath($file);

            if (
                preg_match('/\A([0-9]{3})_[a-z0-9_]+\.sql\z/', $filename, $match) !== 1
                || $resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)
            ) {
                throw new RuntimeException('Migration filename or location is invalid.');
            }

            if (isset($numbers[$match[1]])) {
                throw new RuntimeException('Migration numbers must be unique.');
            }

            $numbers[$match[1]] = true;
        }

        if (basename($files[0]) !== '000_create_migrations.sql') {
            throw new RuntimeException('Tracking migration 000_create_migrations.sql is required.');
        }

        $this->migrations->execute($this->read($files[0]));
        $applied = $this->migrations->applied();
        $completed = [];

        foreach ($files as $index => $file) {
            $filename = basename($file);

            if (in_array($filename, $applied, true)) {
                continue;
            }

            if ($index !== 0) {
                // MySQL DDL implicitly commits; do not promise transactional rollback.
                $this->migrations->execute($this->read($file));
            }

            $this->migrations->record($filename);
            $completed[] = $filename;
        }

        return $completed;
    }

    private function read(string $file): string
    {
        $sql = file_get_contents($file);

        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Migration SQL is missing or empty.');
        }

        return $sql;
    }
}
