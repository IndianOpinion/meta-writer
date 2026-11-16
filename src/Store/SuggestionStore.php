<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Store;

use IndianOpinion\MetaWriter\Exception\StorageException;
use IndianOpinion\MetaWriter\Suggestion;

/**
 * Where suggestions live. The shipped implementation is a custom table.
 */
interface SuggestionStore
{
    public const COLUMNS = [
        'id', 'post_id', 'fields', 'title', 'description', 'title_len', 'description_len', 'flags', 'status',
        'input_hash', 'route', 'prompt_version', 'error_class', 'error_message', 'raw', 'description_hash',
        'created_at', 'created_by', 'decided_at', 'decided_by',
    ];

    /**
     * @param array<string, int|string|null> $row column => value; created_at as a Unix time
     * @throws StorageException
     */
    public function insert(array $row): int;

    public function find(int $id): ?Suggestion;

    public function latestForPost(int $postId): ?Suggestion;

    public function latestForPostWithStatus(int $postId, string $status): ?Suggestion;

    /**
     * Compare-and-set: move $id from one of $from to $to, applying $changes,
     * only when its status is still one of $from. True when this call won.
     *
     * @param list<string>                   $from
     * @param array<string, int|string|null> $changes
     */
    public function transition(int $id, array $from, string $to, array $changes = []): bool;

    /**
     * @param array{status?: string, flag?: string, page?: int, per_page?: int, post_id?: int, include_trashed?: bool} $args
     * @return array{items: list<Suggestion>, total: int}
     */
    public function query(array $args): array;

    public function descriptionHashExists(string $hash, int $excludePostId): bool;

    public function deleteForPost(int $postId): int;

    /** @return array<string, int> status => count, trashed posts excluded */
    public function countByStatus(): array;

    /** @param list<string> $statuses */
    public function purge(int $olderThan, array $statuses): int;

    public function clearRaw(int $olderThan): int;

    public function install(): void;

    /** @return list<string> */
    public function missingColumns(): array;

    public function drop(): void;
}
