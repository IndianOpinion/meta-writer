<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

/**
 * The result of normalising and shortening one value.
 */
final class FitResult
{
    public const EMPTY = 'empty';
    public const TOO_SHORT = 'too_short';
    public const SAME_AS_TITLE = 'same_as_title';
    public const WRONG_SCRIPT = 'wrong_script';

    public function __construct(
        public readonly string $text,
        public readonly int $length,
        public readonly bool $ok,
        public readonly bool $trimmed,
        public readonly ?string $reason,
    ) {
    }

    public function fail(string $reason): self
    {
        return new self($this->text, $this->length, false, $this->trimmed, $reason);
    }
}
