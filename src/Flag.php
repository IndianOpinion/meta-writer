<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

/**
 * Suggestion flags shown to the reviewer.
 */
final class Flag
{
    public const TRIMMED = 'trimmed';
    public const DUPLICATE = 'duplicate';
    public const RETRIED = 'retried';
    public const UNSUPPORTED_DETAIL = 'unsupported_detail';
    public const ALL = [self::TRIMMED, self::DUPLICATE, self::RETRIED, self::UNSUPPORTED_DETAIL];

    private function __construct()
    {
    }
}
