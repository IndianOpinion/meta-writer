<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * The suggestion is not pending (already decided, or missing).
 */
final class NotPendingException extends MetaWriterException
{
    public function errorCode(): string
    {
        return 'meta_writer_not_pending';
    }
}
