<?php

declare(strict_types=1);

namespace Pediacare\Repositories;

use InvalidArgumentException;
use RuntimeException;
use Pediacare\Core\Database;
use Pediacare\Support\BackupCipher;

final class BackupRepository
{
    public function __construct(
        private readonly array $database,
        private readonly string $dumpBinary,
        private readonly string $mysqlBinary,
        private readonly string $privateDirectory,
    ) {
        if (!is_string($database['host'] ?? null) || preg_match('/\A[a-zA-Z0-9.:-]+\z/', $database['host']) !== 1
            || !is_int($database['port'] ?? null) || $database['port'] < 1 || $database['port'] > 65535
            || !is_string($database['name'] ?? null) || preg_match('/\A[a-zA-Z0-9_]+\z/', $database['name']) !== 1
            || !is_string($database['username'] ?? null) || !is_string($database['password'] ?? null)
            || str_contains($database['username'], "\0") || str_contains($database['password'], "\0")
            || ($database['charset'] ?? null) !== 'utf8mb4') {
            throw new InvalidArgumentException('Backup database configuration is invalid.');
        }
    }

    public function dump(): string
    {
        // Consistent InnoDB snapshot; no CREATE DATABASE/USE, so recovery can select a different database.
        // https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump
        return $this->client($this->dumpBinary, ['--single-transaction', '--quick', '--skip-lock-tables',
            '--skip-add-locks', '--hex-blob', '--no-tablespaces', '--skip-comments', $this->database['name']]);
    }

    public function restore(string $sql, string $target): void
    {
        if (preg_match('/\A[a-zA-Z0-9_]+\z/', $target) !== 1 || strcasecmp($target, $this->database['name']) === 0) {
            throw new InvalidArgumentException('Restore requires a separate database name.');
        }
        $config = $this->database;
        $config['name'] = $target;
        $pdo = Database::connect($config);
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :database');
        $statement->execute(['database' => $target]);
        if ((int) $statement->fetchColumn() !== 0) {
            throw new RuntimeException('Restore requires an empty database.');
        }
        $this->client($this->mysqlBinary, ['--batch', '--binary-mode', '--local-infile=0', '--connect-timeout=10', $target], $sql);
    }

    private function client(string $binary, array $arguments, ?string $input = null): string
    {
        if (!is_file($binary)) {
            throw new RuntimeException('Configured database client executable is missing.');
        }
        $optionsPath = $this->privateDirectory . '/.client-' . bin2hex(random_bytes(16)) . '.cnf';
        $handle = fopen($optionsPath, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Private database client options could not be created.');
        }
        $process = null;
        $pipes = [];
        try {
            chmod($optionsPath, 0600);
            $options = "[client]\n";
            foreach (['host', 'port', 'username' => 'user', 'password'] as $field => $name) {
                $field = is_int($field) ? $name : $field;
                $value = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '\\r', '\\n'], (string) $this->database[$field]);
                $options .= $name . '="' . $value . "\"\n";
            }
            if (fwrite($handle, $options) !== strlen($options)) {
                throw new RuntimeException('Private database client options could not be written.');
            }
            fclose($handle);
            $handle = null;
            $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            // An argument array bypasses the shell; passwords never appear in the argument list.
            $process = proc_open([$binary, '--defaults-extra-file=' . $optionsPath, '--protocol=TCP',
                '--default-character-set=utf8mb4', ...$arguments], [
                0 => ['pipe', 'r'], 1 => $input === null ? ['pipe', 'w'] : ['file', $null, 'w'],
                2 => ['file', $null, 'w'],
            ], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('Database client could not start.');
            }
            if ($input !== null) {
                for ($offset = 0; $offset < strlen($input); $offset += $written) {
                    $written = fwrite($pipes[0], substr($input, $offset, 65536));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Database restore input failed.');
                    }
                }
            }
            fclose($pipes[0]);
            unset($pipes[0]);
            $output = '';
            if ($input === null) {
                // shortcut: bound memory for a small-center database; upgrade to streaming encryption beyond 32 MiB.
                $output = stream_get_contents($pipes[1], BackupCipher::MAX_BYTES + 1);
                if ($output === false || strlen($output) > BackupCipher::MAX_BYTES) {
                    throw new RuntimeException('Database dump exceeds the backup size limit or could not be read.');
                }
                fclose($pipes[1]);
                unset($pipes[1]);
            }
            $status = proc_close($process);
            $process = null;
            if ($status !== 0) {
                throw new RuntimeException('Database client failed.');
            }

            return $output;
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($optionsPath) && !unlink($optionsPath)) {
                throw new RuntimeException('Private database client options could not be removed.');
            }
        }
    }
}
