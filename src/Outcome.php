<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

/**
 * What happened to one post in a run.
 */
final class Outcome
{
    public const SKIPPED = 'skipped';
    public const PLANNED = 'planned';
    public const GENERATED = 'generated';
    public const FAILED = 'failed';
    public const STOPPED = 'stopped';
    public const QUEUED = 'queued';

    /** @param list<string> $fields */
    public function __construct(
        public readonly string $kind,
        public readonly int $postId,
        public readonly string $reason = '',
        public readonly array $fields = [],
        public readonly int $inputLength = 0,
        public readonly int $calls = 0,
        public readonly ?Suggestion $suggestion = null,
        public readonly string $message = '',
    ) {
    }
}
