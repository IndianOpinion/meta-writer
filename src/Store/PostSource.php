<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Store;

/**
 * Pages through candidate post IDs by cursor (PRD case 9).
 */
interface PostSource
{
    public const ORDER_OLDEST = 'oldest';
    public const ORDER_NEWEST = 'newest';

    /**
     * IDs after $cursor in a stable order. For "oldest" the cursor is an ID;
     * for "newest" it is "Y-m-d H:i:s|ID" of the last post seen.
     *
     * @param list<string> $types
     * @param list<string> $statuses
     * @return list<array{id: int, cursor: string}>
     */
    public function next(array $types, array $statuses, ?string $cursor, int $limit, string $order): array;
}
