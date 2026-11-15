<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Store;

/**
 * The daily call counter and fresh option reads, straight from the
 * database so an object cache flush can never reset a cap (case 78).
 */
interface CounterStore
{
    /**
     * Add one call to $day's counter only while it is below $cap. Atomic
     * across processes. True when the call may go ahead.
     */
    public function increment(string $day, int $cap): bool;

    public function get(string $day): int;

    /** Remove counters for days before $day. */
    public function purgeBefore(string $day): int;

    /** Read an option from the database, bypassing every cache. */
    public function freshOption(string $name): ?string;

    /** The same, for a network option on multisite. */
    public function freshNetworkOption(string $name): ?string;
}
