<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Clock;

/**
 * The real clock. The day comes from wp_date(), which uses the site's
 * time zone setting at the moment of the call (PRD case 77).
 */
final class WpClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }

    public function siteDay(): string
    {
        $day = wp_date('Y-m-d', (int) $this->now());

        return is_string($day) ? $day : gmdate('Y-m-d');
    }
}
