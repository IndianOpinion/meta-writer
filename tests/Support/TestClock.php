<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Support;

use IndianOpinion\MetaWriter\Clock\Clock;

final class TestClock implements Clock
{
    public function __construct(public float $time = 1_780_000_000.0)
    {
    }

    public function now(): float
    {
        return $this->time;
    }

    public function siteDay(): string
    {
        return (string) wp_date('Y-m-d', (int) $this->time);
    }

    public function advance(float $seconds): void
    {
        $this->time += $seconds;
    }
}
