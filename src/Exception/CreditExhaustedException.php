<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * Every provider is out of credit.
 */
final class CreditExhaustedException extends MetaWriterException
{
    public function errorCode(): string
    {
        return 'meta_writer_credit_exhausted';
    }
}
