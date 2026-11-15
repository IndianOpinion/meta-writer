<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Support;

use IndianOpinion\MetaWriter\Exception\StorageException;
use IndianOpinion\MetaWriter\Store\SuggestionStore;
use IndianOpinion\MetaWriter\Store\WpdbSuggestionStore;
use IndianOpinion\MetaWriter\Suggestion;

/**
 * The same contract as the table store, in memory, per blog.
 */
final class MemorySuggestionStore implements SuggestionStore
{
    /** @var array<int, array<int, array<string, mixed>>> blog => id => row */
    public array $rows = [];
    private int $next = 1;
    public bool $failInsert = false;

    /** @return array<int, array<string, mixed>> */
    private function &blog(): array
    {
        $this->rows[\WpState::$blog] ??= [];

        return $this->rows[\WpState::$blog];
    }

    public function insert(array $row): int
    {
        if ($this->failInsert) {
            throw new StorageException('insert failed', 'insert');
        }
        $id = $this->next++;
        $row = array_merge(array_fill_keys(self::COLUMNS, null), $row);
        $row['id'] = $id;
        $row['created_at'] = gmdate('Y-m-d H:i:s', (int) ($row['created_at'] ?? time()));
        $rows = &$this->blog();
        $rows[$id] = $row;

        return $id;
    }

    public function find(int $id): ?Suggestion
    {
        $rows = &$this->blog();

        return isset($rows[$id]) ? WpdbSuggestionStore::hydrate($rows[$id]) : null;
    }

    public function latestForPost(int $postId): ?Suggestion
    {
        $rows = &$this->blog();
        foreach (array_reverse($rows, true) as $row) {
            if ((int) $row['post_id'] === $postId) {
                return WpdbSuggestionStore::hydrate($row);
            }
        }

        return null;
    }

    public function latestForPostWithStatus(int $postId, string $status): ?Suggestion
    {
        $rows = &$this->blog();
        foreach (array_reverse($rows, true) as $row) {
            if ((int) $row['post_id'] === $postId && $row['status'] === $status) {
                return WpdbSuggestionStore::hydrate($row);
            }
        }

        return null;
    }

    public function transition(int $id, array $from, string $to, array $changes = []): bool
    {
        $rows = &$this->blog();
        if (!isset($rows[$id]) || !in_array($rows[$id]['status'], $from, true)) {
            return false;
        }
        unset($changes['id'], $changes['status']);
        if (isset($changes['decided_at'])) {
            $changes['decided_at'] = gmdate('Y-m-d H:i:s', (int) $changes['decided_at']);
        }
        $rows[$id] = array_merge($rows[$id], array_intersect_key($changes, array_flip(self::COLUMNS)), ['status' => $to]);

        return true;
    }

    public function query(array $args): array
    {
        $rows = &$this->blog();
        $items = [];
        foreach (array_reverse($rows, true) as $row) {
            if (!empty($args['status']) && $row['status'] !== $args['status']) {
                continue;
            }
            if (!empty($args['flag']) && !in_array($args['flag'], explode(',', (string) $row['flags']), true)) {
                continue;
            }
            if (!empty($args['post_id']) && (int) $row['post_id'] !== (int) $args['post_id']) {
                continue;
            }
            if (empty($args['include_trashed'])) {
                $post = get_post((int) $row['post_id']);
                if ($post === null || $post->post_status === 'trash') {
                    continue;
                }
            }
            $items[] = WpdbSuggestionStore::hydrate($row);
        }
        $per = max(1, (int) ($args['per_page'] ?? 20));
        $page = max(1, (int) ($args['page'] ?? 1));

        return ['items' => array_slice($items, ($page - 1) * $per, $per), 'total' => count($items)];
    }

    public function descriptionHashExists(string $hash, int $excludePostId): bool
    {
        $rows = &$this->blog();
        foreach ($rows as $row) {
            if ($hash !== '' && $row['description_hash'] === $hash && (int) $row['post_id'] !== $excludePostId && in_array($row['status'], ['pending', 'accepted'], true)) {
                return true;
            }
        }

        return false;
    }

    public function deleteForPost(int $postId): int
    {
        $rows = &$this->blog();
        $n = 0;
        foreach ($rows as $id => $row) {
            if ((int) $row['post_id'] === $postId) {
                unset($rows[$id]);
                $n++;
            }
        }

        return $n;
    }

    public function countByStatus(): array
    {
        $out = [];
        foreach ($this->query(['per_page' => 100000])['items'] as $s) {
            $out[$s->status] = ($out[$s->status] ?? 0) + 1;
        }

        return $out;
    }

    public function purge(int $olderThan, array $statuses): int
    {
        $rows = &$this->blog();
        $n = 0;
        foreach ($rows as $id => $row) {
            if (strtotime($row['created_at'] . ' UTC') < $olderThan && in_array($row['status'], $statuses, true)) {
                unset($rows[$id]);
                $n++;
            }
        }

        return $n;
    }

    public function clearRaw(int $olderThan): int
    {
        $rows = &$this->blog();
        $n = 0;
        foreach ($rows as $id => $row) {
            if ($row['raw'] !== null && strtotime($row['created_at'] . ' UTC') < $olderThan) {
                $rows[$id]['raw'] = null;
                $n++;
            }
        }

        return $n;
    }

    public function install(): void
    {
    }

    public function missingColumns(): array
    {
        return [];
    }

    public function drop(): void
    {
        unset($this->rows[\WpState::$blog]);
    }
}
