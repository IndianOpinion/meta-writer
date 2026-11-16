<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

use IndianOpinion\MetaWriter\Clock\Clock;
use IndianOpinion\MetaWriter\Store\CounterStore;

/**
 * The daily call cap (cases 46 to 49). The day is the site's calendar day.
 */
final class DailyCap
{
    public function __construct(
        private readonly CounterStore $store,
        private readonly Settings $settings,
        private readonly Clock $clock,
    ) {
    }

    public function cap(): int
    {
        return max(0, min(Settings::CAP_RANGE[1], $this->settings->int('daily_cap')));
    }

    public function day(): string
    {
        return $this->clock->siteDay();
    }

    public function used(): int
    {
        return $this->store->get($this->day());
    }

    public function remaining(): int
    {
        return max(0, $this->cap() - $this->used());
    }

    /** Take one call. False (and the cap_reached action) when none is left. */
    public function take(): bool
    {
        $cap = $this->cap();
        $day = $this->day();
        if ($this->store->increment($day, $cap)) {
            return true;
        }
        do_action('meta_writer_cap_reached', $cap, $day);

        return false;
    }
}
