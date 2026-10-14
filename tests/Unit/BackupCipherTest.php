<?php

declare(strict_types=1);

use Pediacare\Support\BackupCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupCipherTest extends TestCase
{
    public function test_T40_round_trip_hides_fake_health_data_and_randomizes_each_backup(): void
    {
        $cipher = new BackupCipher(base64_encode(random_bytes(32)));
        $sql = "FAKE Child Ñ\0 ' quoted\nCREATE TABLE fake (id INT);";
        $first = $cipher->encrypt($sql);
        self::assertSame($sql, $cipher->decrypt($first));
        self::assertNotSame($first, $cipher->encrypt($sql));
        self::assertStringNotContainsString('FAKE Child', $first);
        self::assertSame(strlen($sql) + BackupCipher::OVERHEAD, strlen($first));
    }

    #[DataProvider('damage')]
    public function test_T40_wrong_keys_and_modified_or_truncated_files_fail_authentication(string $damage): void
    {
        $cipher = new BackupCipher(base64_encode(random_bytes(32)));
        $backup = $cipher->encrypt('FAKE sensitive health data');
        if ($damage === 'wrong key') {
            $cipher = new BackupCipher(base64_encode(random_bytes(32)));
        } elseif ($damage === 'truncated') {
            $backup = substr($backup, 0, -1);
        } elseif ($damage === 'appended') {
            $backup .= 'FAKE';
        } else {
            $offset = ['header' => 0, 'nonce' => 17, 'tag' => 29, 'ciphertext' => 45][$damage];
            $backup[$offset] = chr(ord($backup[$offset]) ^ 1);
        }
        $this->expectException(RuntimeException::class);
        $cipher->decrypt($backup);
    }

    public static function damage(): array
    {
        return array_map(static fn (string $case): array => [$case],
            ['wrong key', 'truncated', 'appended', 'header', 'nonce', 'tag', 'ciphertext']);
    }

    #[DataProvider('invalidKeys')]
    public function test_T40_invalid_key_lengths_are_rejected_instead_of_padded_or_truncated(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupCipher($key);
    }

    public static function invalidKeys(): array
    {
        return [[''], ['FAKE-not-base64!'], [base64_encode(str_repeat('x', 31))], [base64_encode(str_repeat('x', 33))]];
    }

    public function test_T40_empty_dumps_are_not_published_as_backups(): void
    {
        $this->expectException(RuntimeException::class);
        (new BackupCipher(base64_encode(random_bytes(32))))->encrypt('');
    }

    public function test_T40_oversize_dumps_fail_before_encryption(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds the backup size limit');
        (new BackupCipher(base64_encode(random_bytes(32))))->encrypt(str_repeat('x', BackupCipher::MAX_BYTES + 1));
    }
}
