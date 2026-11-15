<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

/**
 * What an accept did, field by field.
 */
final class AcceptResult
{
    /**
     * @param list<string>          $written    fields written to the target
     * @param list<string>          $superseded fields not written because the target was filled meanwhile
     * @param array<string, string> $skipped    field => reason
     */
    public function __construct(
        public readonly array $written = [],
        public readonly array $superseded = [],
        public readonly array $skipped = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['written' => $this->written, 'superseded' => $this->superseded, 'skipped' => $this->skipped];
    }
}
