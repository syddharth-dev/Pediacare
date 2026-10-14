<?php

declare(strict_types=1);

namespace Pediacare\Domain;

final class MeasurementResult
{
    /** @param list<string> $flags */
    public function __construct(
        public readonly ?int $id,
        public readonly array $data,
        public readonly array $flags = [],
        public readonly ?string $confirmationToken = null,
    ) {
    }
}
