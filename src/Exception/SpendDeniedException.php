<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * The spend meter refused the call.
 */
final class SpendDeniedException extends MetaWriterException
{
    public function errorCode(): string
    {
        return 'meta_writer_spend_denied';
    }
}
