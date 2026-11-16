<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * Today's call cap is used up.
 */
final class CappedException extends MetaWriterException
{
    public function errorCode(): string
    {
        return 'meta_writer_capped';
    }
}
