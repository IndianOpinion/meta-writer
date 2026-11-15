<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * Input or edited text failed validation.
 */
final class InvalidException extends MetaWriterException
{
    public function errorCode(): string
    {
        return 'meta_writer_invalid';
    }
}
