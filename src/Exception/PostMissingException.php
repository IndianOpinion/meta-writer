<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * The post no longer exists.
 */
final class PostMissingException extends MetaWriterException
{
    public function errorCode(): string
    {
        return 'meta_writer_post_missing';
    }
}
