<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

/**
 * Suggestion statuses.
 */
final class Status
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const STALE = 'stale';
    public const SUPERSEDED = 'superseded';
    public const FAILED = 'failed';
    public const ALL = [self::PENDING, self::ACCEPTED, self::REJECTED, self::STALE, self::SUPERSEDED, self::FAILED];

    private function __construct()
    {
    }
}
