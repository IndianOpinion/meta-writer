<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Exception;

/**
 * No usable target: the chosen SEO plugin is inactive, or auto found more than one.
 */
final class TargetUnavailableException extends MetaWriterException
{
    public function errorCode(): string
    {
        return 'meta_writer_target_unavailable';
    }
}
