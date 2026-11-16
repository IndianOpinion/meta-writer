<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * A database read or write failed.
 */
final class StorageException extends MetaWriterException
{
    public function errorCode(): string
    {
        return 'meta_writer_storage';
    }
}
