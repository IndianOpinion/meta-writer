<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

/**
 * Every skip and stop reason code. The list is fixed (PRD section 11).
 */
final class Reason
{
    public const EXCLUDED = 'excluded';
    public const BOTH_FILLED = 'both_filled';
    public const PENDING_EXISTS = 'pending_exists';
    public const REJECTED_BEFORE = 'rejected_before';
    public const TOO_LITTLE_CONTENT = 'too_little_content';
    public const CAPPED = 'capped';
    public const DISABLED = 'disabled';
    public const SPEND_DENIED = 'spend_denied';
    public const CREDIT_EXHAUSTED = 'credit_exhausted';
    public const TARGET_UNAVAILABLE = 'target_unavailable';
    public const TIME = 'time';
    public const LIMIT = 'limit';
    public const EMPTY = 'empty';

    /** Not a candidate: wrong type, wrong status, password, revision. */
    public const NOT_CANDIDATE = 'not_candidate';

    /** Stop reasons that mean "nothing more allowed", for CLI exit code 2. */
    public const BLOCKING = [self::CAPPED, self::DISABLED, self::SPEND_DENIED];

    private function __construct()
    {
    }
}
