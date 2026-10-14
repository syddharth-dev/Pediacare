<?php

declare(strict_types=1);

use Pediacare\Repositories\PurokRepository;
use Pediacare\Services\PurokService;
use Pediacare\Validation\PurokValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PurokServiceTest extends TestCase
{
    public function test_T15_create_and_rename_validate_names_and_preserve_purok_id(): void
    {
        $puroks = $this->createMock(PurokRepository::class);
        $puroks->expects(self::once())->method('insert')->with('FAKE Sitio');
        $puroks->method('findById')->with(7)->willReturn(['id' => 7, 'name' => 'FAKE Old']);
        $puroks->expects(self::once())->method('rename')->with(7, 'FAKE Renamed');
        $service = new PurokService($puroks, new PurokValidator());
        $service->create(['name' => ' FAKE Sitio ']);
        $service->rename('7', ['name' => ' FAKE Renamed ']);
        self::assertSame(str_repeat('ñ', 255), (new PurokValidator())->name(['name' => str_repeat('ñ', 255)]));
    }

    #[DataProvider('invalidNames')]
    public function test_T15_invalid_name_never_reaches_database(array $input): void
    {
        $puroks = $this->createMock(PurokRepository::class);
        $puroks->expects(self::never())->method('insert');
        $this->expectException(InvalidArgumentException::class);
        (new PurokService($puroks, new PurokValidator()))->create($input);
    }

    public static function invalidNames(): array
    {
        return [[[]], [['name' => []]], [['name' => '  ']], [['name' => str_repeat('ñ', 256)]],
            [['name' => "FAKE\0Sitio"]], [['name' => "\xff"]]];
    }

    #[DataProvider('invalidIds')]
    public function test_T15_invalid_id_never_reaches_database(string $id): void
    {
        $puroks = $this->createMock(PurokRepository::class);
        $puroks->expects(self::never())->method('findById');
        $puroks->expects(self::never())->method('rename');
        $this->expectException(InvalidArgumentException::class);
        (new PurokService($puroks, new PurokValidator()))->rename($id, ['name' => 'FAKE']);
    }

    public static function invalidIds(): array
    {
        return [['0'], ['-1'], ['4294967296'], ['7 OR 1=1']];
    }

    public function test_T15_missing_purok_is_not_renamed(): void
    {
        $puroks = $this->createMock(PurokRepository::class);
        $puroks->method('findById')->willReturn(null);
        $puroks->expects(self::never())->method('rename');
        $this->expectException(InvalidArgumentException::class);
        (new PurokService($puroks, new PurokValidator()))->rename('7', ['name' => 'FAKE']);
    }

    #[DataProvider('writes')]
    public function test_T15_duplicate_name_is_a_validation_error_for_both_writes(string $operation): void
    {
        $error = new PDOException('FAKE private SQL');
        $error->errorInfo = ['23000', 1062, 'FAKE private SQL'];
        $puroks = $this->createMock(PurokRepository::class);
        $puroks->method('findById')->willReturn(['id' => 7]);
        $puroks->method($operation === 'create' ? 'insert' : 'rename')->willThrowException($error);
        $service = new PurokService($puroks, new PurokValidator());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A purok or sitio already uses this name. Choose a different name.');
        if ($operation === 'create') {
            $service->create(['name' => 'FAKE']);
        } else {
            $service->rename('7', ['name' => 'FAKE']);
        }
    }

    public static function writes(): array
    {
        return [['create'], ['rename']];
    }

    public function test_T15_database_failures_propagate(): void
    {
        $puroks = $this->createMock(PurokRepository::class);
        $puroks->method('insert')->willThrowException(new PDOException('FAKE unavailable'));
        $this->expectException(PDOException::class);
        (new PurokService($puroks, new PurokValidator()))->create(['name' => 'FAKE']);
    }
}
