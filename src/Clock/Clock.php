<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Clock;

interface Clock
{
    /** Seconds since the epoch, with fractions. */
    public function now(): float;

    /** Today's date (Y-m-d) in the site's time zone. */
    public function siteDay(): string;
}
