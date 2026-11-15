<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

use IndianOpinion\MetaWriter\Adapter\TargetAdapter;

/**
 * What meta-writer wrote, per post and field (PRD cases 30 and 31).
 *
 * Stored as JSON in the post meta key _meta_writer_written:
 * {"title": [record, ...], "description": [...]}, newest last, at most
 * five records per field. A target is "ours" only while it is byte-equal
 * to the newest record written through the same adapter.
 */
final class Provenance
{
    public const META_KEY = '_meta_writer_written';
    public const KEEP = 5;

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function all(int $postId): array
    {
        $raw = get_post_meta($postId, self::META_KEY, true);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach (Field::ALL as $field) {
            $records = $data[$field] ?? [];
            if (is_array($records)) {
                $out[$field] = array_values(array_filter($records, 'is_array'));
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null the newest record for this field and adapter */
    public function last(int $postId, string $field, ?string $adapterId = null): ?array
    {
        $records = $this->all($postId)[$field] ?? [];
        for ($i = count($records) - 1; $i >= 0; $i--) {
            $record = $records[$i];
            if (!empty($record['reverted'])) {
                continue;
            }
            if ($adapterId === null || ($record['adapter'] ?? '') === $adapterId) {
                return $record;
            }
        }

        return null;
    }

    /** Is $current byte-equal to what meta-writer last wrote here? */
    public function isOurs(int $postId, string $field, string $current, string $adapterId): bool
    {
        if ($current === '') {
            return false;
        }
        $record = $this->last($postId, $field, $adapterId);
        if ($record === null || !is_string($record['value'] ?? null)) {
            return false;
        }

        return hash_equals((string) $record['value'], $current)
            && hash_equals((string) ($record['sha256'] ?? ''), hash('sha256', $current));
    }

    /**
     * Classify a target value: empty, ours or manual.
     *
     * Whitespace-only counts as empty (case 62). With $templateAsEmpty, a
     * value of only the plugin's template variables counts as empty too.
     */
    public function classify(int $postId, string $field, TargetAdapter $adapter, bool $templateAsEmpty): string
    {
        $value = $adapter->read($postId, $field);
        if (trim($value) === '') {
            return 'empty';
        }
        if ($this->isOurs($postId, $field, $value, $adapter->id())) {
            return 'ours';
        }
        if ($templateAsEmpty && $adapter->isTemplateOnly($value)) {
            return 'empty';
        }

        return 'manual';
    }

    public function record(int $postId, string $field, string $value, string $previous, int $userId, string $adapterId, int $suggestionId, int $time): void
    {
        $all = $this->all($postId);
        $records = $all[$field] ?? [];
        $records[] = [
            'value' => $value,
            'sha256' => hash('sha256', $value),
            'previous' => $previous,
            'user' => $userId,
            'time' => $time,
            'adapter' => $adapterId,
            'suggestion' => $suggestionId,
        ];
        $all[$field] = array_slice($records, -self::KEEP);
        $this->save($postId, $all);
    }

    /** Mark the newest record for this field and adapter as reverted. */
    public function markReverted(int $postId, string $field, string $adapterId, int $userId, int $time): void
    {
        $all = $this->all($postId);
        $records = $all[$field] ?? [];
        for ($i = count($records) - 1; $i >= 0; $i--) {
            if (empty($records[$i]['reverted']) && ($records[$i]['adapter'] ?? '') === $adapterId) {
                $records[$i]['reverted'] = ['user' => $userId, 'time' => $time];
                break;
            }
        }
        $all[$field] = $records;
        $this->save($postId, $all);
    }

    /** @param array<string, list<array<string, mixed>>> $all */
    private function save(int $postId, array $all): void
    {
        $json = (string) wp_json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        update_post_meta($postId, self::META_KEY, wp_slash($json));
    }
}
