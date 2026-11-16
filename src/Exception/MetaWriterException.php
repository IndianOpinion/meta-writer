<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * Base class for every exception meta-writer throws internally.
 *
 * Public functions convert these to WP_Error at the boundary, using
 * errorCode() as the WP_Error code and reason() as data.
 */
abstract class MetaWriterException extends \RuntimeException
{
    private string $reason;

    public function __construct(string $message, string $reason = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->reason = $reason;
    }

    /** The WP_Error code, for example meta_writer_capped. */
    abstract public function errorCode(): string;

    /** A reason code from the fixed list, or an empty string. */
    public function reason(): string
    {
        return $this->reason;
    }
}
