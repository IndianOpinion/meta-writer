<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Support;

use IndianOpinion\MetaWriter\Store\CounterStore;

/**
 * Counters per blog and day. freshOption reads the stub's option table,
 * standing in for the database row behind the object cache.
 */
final class MemoryCounterStore implements CounterStore
{
    /** @var array<int, array<string, int>> */
    public array $counters = [];

    public function increment(string $day, int $cap): bool
    {
        $current = $this->counters[\WpState::$blog][$day] ?? 0;
        if ($cap <= 0 || $current >= $cap) {
            return false;
        }
        $this->counters[\WpState::$blog][$day] = $current + 1;

        return true;
    }

    public function get(string $day): int
    {
        return $this->counters[\WpState::$blog][$day] ?? 0;
    }

    public function purgeBefore(string $day): int
    {
        $n = 0;
        foreach (array_keys($this->counters[\WpState::$blog] ?? []) as $d) {
            if (strcmp($d, $day) < 0) {
                unset($this->counters[\WpState::$blog][$d]);
                $n++;
            }
        }

        return $n;
    }

    public function freshOption(string $name): ?string
    {
        $v = get_option($name, null);

        return $v === null ? null : (is_scalar($v) ? (string) $v : '');
    }

    public function freshNetworkOption(string $name): ?string
    {
        if (!is_multisite()) {
            return null;
        }
        $v = get_site_option($name, null);

        return $v === null ? null : (is_scalar($v) ? (string) $v : '');
    }
}
