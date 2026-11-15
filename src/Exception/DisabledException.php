<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * The kill switch is on, or the model library is missing.
 */
final class DisabledException extends MetaWriterException
{
    public function errorCode(): string
    {
        return 'meta_writer_disabled';
    }
}
