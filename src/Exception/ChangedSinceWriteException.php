<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * The target no longer holds what meta-writer wrote.
 */
final class ChangedSinceWriteException extends MetaWriterException
{
    public function errorCode(): string
    {
        return 'meta_writer_changed_since_write';
    }
}
