<?php

declare(strict_types=1);

namespace Pediacare\Support;

use InvalidArgumentException;
use RuntimeException;

final class BackupCipher
{
    private const HEADER = "PEDIACARE-BACKUP\x01";
    public const MAX_BYTES = 33554432;
    public const OVERHEAD = 45;
    private readonly string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new InvalidArgumentException('Backup encryption requires a base64-encoded 32-byte random key.');
        }
        $this->key = $key;
    }

    public function encrypt(string $sql): string
    {
        if ($sql === '' || strlen($sql) > self::MAX_BYTES) {
            throw new RuntimeException('Database dump is empty or exceeds the backup size limit.');
        }
        // PHP OpenSSL AEAD API: https://www.php.net/manual/en/function.openssl-encrypt.php
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($sql, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, self::HEADER, 16);
        if ($encrypted === false || strlen($tag) !== 16) {
            throw new RuntimeException('Backup encryption failed.');
        }

        return self::HEADER . $iv . $tag . $encrypted;
    }

    public function decrypt(string $backup): string
    {
        $headerLength = strlen(self::HEADER);
        if (strlen($backup) <= self::OVERHEAD || strlen($backup) > self::MAX_BYTES + self::OVERHEAD
            || substr($backup, 0, $headerLength) !== self::HEADER) {
            throw new RuntimeException('Backup format or size is invalid.');
        }
        $sql = openssl_decrypt(substr($backup, self::OVERHEAD), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA,
            substr($backup, $headerLength, 12), substr($backup, $headerLength + 12, 16), self::HEADER);
        if ($sql === false || $sql === '') {
            throw new RuntimeException('Backup authentication failed.');
        }

        return $sql;
    }
}
