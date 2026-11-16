<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * A model call failed. errorClass() names the underlying failure (for
 * example RefusalException) without any provider text beyond a short,
 * redacted message.
 */
final class CallFailedException extends MetaWriterException
{
    private string $errorClass;

    public function __construct(string $message, string $errorClass, ?\Throwable $previous = null)
    {
        parent::__construct($message, 'call_failed', $previous);
        $this->errorClass = $errorClass;
    }

    public function errorCode(): string
    {
        return 'meta_writer_call_failed';
    }

    public function errorClass(): string
    {
        return $this->errorClass;
    }
}
