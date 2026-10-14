<?php

declare(strict_types=1);

namespace Pediacare\Domain;

final class RegistrationResult
{
    public function __construct(
        public readonly ?string $recordNumber,
        public readonly array $duplicates = [],
        public readonly ?int $childId = null
    ) {
    }
}
