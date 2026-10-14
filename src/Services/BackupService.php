<?php

declare(strict_types=1);

namespace Pediacare\Services;

use RuntimeException;
use Pediacare\Repositories\BackupRepository;
use Pediacare\Support\BackupCipher;

final class BackupService
{
    private readonly string $directory;

    public function __construct(
        private readonly BackupRepository $repository,
        private readonly BackupCipher $cipher,
        string $directory,
        string $projectRoot,
        private readonly array $protectedDatabases = [],
    ) {
        $resolved = realpath($directory);
        $project = realpath($projectRoot);
        if ($resolved === false || $project === false || !is_dir($resolved) || !is_writable($resolved)) {
            throw new RuntimeException('Configure an existing writable private backup directory.');
        }
        $normalized = strtolower(str_replace('\\', '/', $resolved));
        $root = strtolower(str_replace('\\', '/', $project));
        if ($normalized === $root || str_starts_with($normalized, $root . '/')) {
            throw new RuntimeException('Backups must be outside the project directory.');
        }
        $this->directory = $resolved;
    }

    public function backup(): string
    {
        $encrypted = $this->cipher->encrypt($this->repository->dump());
        $name = 'pediacare-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.pcbackup';
        $path = $this->directory . '/' . $name;
        $temporary = $path . '.part';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Encrypted backup could not be created.');
        }
        try {
            chmod($temporary, 0600);
            if (fwrite($handle, $encrypted) !== strlen($encrypted) || !fflush($handle)) {
                throw new RuntimeException('Encrypted backup could not be written.');
            }
            fclose($handle);
            $handle = null;
            if (file_exists($path) || !rename($temporary, $path)) {
                throw new RuntimeException('Encrypted backup could not be published.');
            }

            return $name;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function restore(string $path, string $target): void
    {
        if (in_array(strtolower($target), array_map('strtolower', $this->protectedDatabases), true)) {
            throw new RuntimeException('Restore cannot target the application or test database.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Encrypted backup could not be opened.');
        }
        try {
            $encrypted = stream_get_contents($handle, BackupCipher::MAX_BYTES + BackupCipher::OVERHEAD + 1);
        } finally {
            fclose($handle);
        }
        if ($encrypted === false) {
            throw new RuntimeException('Encrypted backup could not be read.');
        }
        // Authenticate the entire file before opening the destination database or sending SQL.
        $this->repository->restore($this->cipher->decrypt($encrypted), $target);
    }
}
