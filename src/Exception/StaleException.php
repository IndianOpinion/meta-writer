<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * The post changed since the suggestion was made.
 */
final class StaleException extends MetaWriterException
{
    public function errorCode(): string
    {
        return 'meta_writer_stale';
    }
}
