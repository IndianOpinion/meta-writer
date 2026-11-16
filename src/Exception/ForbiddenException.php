<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * The current user lacks a capability for this action.
 */
final class ForbiddenException extends MetaWriterException
{
    public function errorCode(): string
    {
        return 'meta_writer_forbidden';
    }
}
