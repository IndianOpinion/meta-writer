<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Store;

use IndianOpinion\MetaWriter\Exception\StorageException;
use IndianOpinion\MetaWriter\Suggestion;

/**
 * Suggestions in {prefix}meta_writer_suggestions.
 */
final class WpdbSuggestionStore implements SuggestionStore
{
    public function __construct(private readonly \wpdb $db)
    {
    }

    public function table(): string
    {
        return $this->db->prefix . 'meta_writer_suggestions';
    }

    public function insert(array $row): int
    {
        $row = array_intersect_key($row, array_flip(self::COLUMNS));
        unset($row['id']);
        $row['created_at'] = self::datetime((int) ($row['created_at'] ?? time()));
        if (isset($row['decided_at'])) {
            $row['decided_at'] = self::datetime((int) $row['decided_at']);
        }
        $ok = $this->db->insert($this->table(), $row);
        if ($ok === false || (int) $this->db->insert_id <= 0) {
            throw new StorageException('Could not store the suggestion: ' . substr((string) $this->db->last_error, 0, 200), 'insert');
        }

        return (int) $this->db->insert_id;
    }

    public function find(int $id): ?Suggestion
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id), ARRAY_A);

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function latestForPost(int $postId): ?Suggestion
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM ' . $this->table() . ' WHERE post_id = %d ORDER BY id DESC LIMIT 1', $postId), ARRAY_A);

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function latestForPostWithStatus(int $postId, string $status): ?Suggestion
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM ' . $this->table() . ' WHERE post_id = %d AND status = %s ORDER BY id DESC LIMIT 1', $postId, $status), ARRAY_A);

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function transition(int $id, array $from, string $to, array $changes = []): bool
    {
        if ($from === []) {
            return false;
        }
        $changes = array_intersect_key($changes, array_flip(self::COLUMNS));
        unset($changes['id'], $changes['status']);
        if (isset($changes['decided_at'])) {
            $changes['decided_at'] = self::datetime((int) $changes['decided_at']);
        }
        $set = ['status = %s'];
        $args = [$to];
        foreach ($changes as $column => $value) {
            if ($value === null) {
                $set[] = $column . ' = NULL';
                continue;
            }
            $set[] = $column . (is_int($value) ? ' = %d' : ' = %s');
            $args[] = $value;
        }
        $args[] = $id;
        $placeholders = implode(', ', array_fill(0, count($from), '%s'));
        foreach ($from as $status) {
            $args[] = $status;
        }
        $sql = 'UPDATE ' . $this->table() . ' SET ' . implode(', ', $set) . ' WHERE id = %d AND status IN (' . $placeholders . ')';
        $result = $this->db->query((string) $this->db->prepare($sql, ...$args));

        return is_int($result) && $result === 1;
    }

    public function query(array $args): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($args['status'])) {
            $where[] = 's.status = %s';
            $params[] = (string) $args['status'];
        }
        if (!empty($args['flag'])) {
            $where[] = 'FIND_IN_SET(%s, s.flags) > 0';
            $params[] = (string) $args['flag'];
        }
        if (!empty($args['post_id'])) {
            $where[] = 's.post_id = %d';
            $params[] = (int) $args['post_id'];
        }
        $join = '';
        if (empty($args['include_trashed'])) {
            $join = ' INNER JOIN ' . $this->db->posts . ' p ON p.ID = s.post_id';
            $where[] = "p.post_status <> 'trash'";
        }
        $perPage = max(1, min(500, (int) ($args['per_page'] ?? 20)));
        $page = max(1, (int) ($args['page'] ?? 1));
        $base = ' FROM ' . $this->table() . ' s' . $join . ' WHERE ' . implode(' AND ', $where);
        $countSql = 'SELECT COUNT(*)' . $base;
        $listSql = 'SELECT s.*' . $base . ' ORDER BY s.id DESC LIMIT %d OFFSET %d';
        $total = (int) $this->db->get_var($params === [] ? $countSql : $this->db->prepare($countSql, ...$params));
        $rows = $this->db->get_results($this->db->prepare($listSql, ...array_merge($params, [$perPage, ($page - 1) * $perPage])), ARRAY_A);
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $items[] = self::hydrate($row);
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    public function descriptionHashExists(string $hash, int $excludePostId): bool
    {
        if ($hash === '') {
            return false;
        }
        $found = $this->db->get_var($this->db->prepare(
            'SELECT id FROM ' . $this->table() . " WHERE description_hash = %s AND post_id <> %d AND status IN ('pending','accepted') LIMIT 1",
            $hash,
            $excludePostId,
        ));

        return $found !== null;
    }

    public function deleteForPost(int $postId): int
    {
        $result = $this->db->delete($this->table(), ['post_id' => $postId], ['%d']);

        return is_int($result) ? $result : 0;
    }

    public function countByStatus(): array
    {
        $rows = $this->db->get_results(
            'SELECT s.status, COUNT(*) AS n FROM ' . $this->table() . ' s INNER JOIN ' . $this->db->posts . " p ON p.ID = s.post_id WHERE p.post_status <> 'trash' GROUP BY s.status",
            ARRAY_A,
        );
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[(string) $row['status']] = (int) $row['n'];
            }
        }

        return $out;
    }

    public function purge(int $olderThan, array $statuses): int
    {
        if ($statuses === []) {
            return 0;
        }
        $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
        $result = $this->db->query((string) $this->db->prepare(
            'DELETE FROM ' . $this->table() . ' WHERE created_at < %s AND status IN (' . $placeholders . ')',
            self::datetime($olderThan),
            ...$statuses,
        ));

        return is_int($result) ? $result : 0;
    }

    public function clearRaw(int $olderThan): int
    {
        $result = $this->db->query((string) $this->db->prepare(
            'UPDATE ' . $this->table() . ' SET raw = NULL WHERE raw IS NOT NULL AND created_at < %s',
            self::datetime($olderThan),
        ));

        return is_int($result) ? $result : 0;
    }

    public function install(): void
    {
        $charset = $this->db->get_charset_collate();
        $sql = 'CREATE TABLE ' . $this->table() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  fields varchar(40) NOT NULL DEFAULT '',
  title text NOT NULL,
  description text NOT NULL,
  title_len smallint(5) unsigned NOT NULL DEFAULT 0,
  description_len smallint(5) unsigned NOT NULL DEFAULT 0,
  flags varchar(191) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  input_hash char(64) NOT NULL DEFAULT '',
  route varchar(191) NOT NULL DEFAULT '',
  prompt_version varchar(64) NOT NULL DEFAULT '',
  error_class varchar(100) NOT NULL DEFAULT '',
  error_message varchar(255) NOT NULL DEFAULT '',
  raw text NULL,
  description_hash char(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  decided_at datetime NULL,
  decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY status_created (status,created_at),
  KEY post_id (post_id),
  KEY description_hash (description_hash)
) $charset;";
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
    }

    public function missingColumns(): array
    {
        $found = $this->db->get_col('SHOW COLUMNS FROM ' . $this->table(), 0);
        $found = is_array($found) ? array_map('strval', $found) : [];

        return array_values(array_diff(self::COLUMNS, $found));
    }

    public function drop(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->table());
    }

    private static function datetime(int $time): string
    {
        return gmdate('Y-m-d H:i:s', $time);
    }

    /** @param array<string, mixed> $row */
    public static function hydrate(array $row): Suggestion
    {
        $csv = static fn (mixed $v): array => array_values(array_filter(explode(',', is_scalar($v) ? (string) $v : ''), static fn (string $s): bool => $s !== ''));
        $time = static function (mixed $v): int {
            if (!is_string($v) || $v === '' || str_starts_with($v, '0000')) {
                return 0;
            }
            $t = strtotime($v . ' UTC');

            return $t === false ? 0 : $t;
        };
        $str = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        $class = $str($row['error_class'] ?? '');
        $message = $str($row['error_message'] ?? '');

        return new Suggestion(
            (int) $str($row['id'] ?? 0),
            (int) $str($row['post_id'] ?? 0),
            $csv($row['fields'] ?? ''),
            $str($row['title'] ?? ''),
            $str($row['description'] ?? ''),
            (int) $str($row['title_len'] ?? 0),
            (int) $str($row['description_len'] ?? 0),
            $csv($row['flags'] ?? ''),
            $str($row['status'] ?? ''),
            $str($row['input_hash'] ?? ''),
            $str($row['route'] ?? ''),
            $str($row['prompt_version'] ?? ''),
            $time($row['created_at'] ?? ''),
            (int) $str($row['created_by'] ?? 0),
            (int) $str($row['decided_by'] ?? 0),
            $time($row['decided_at'] ?? ''),
            $class === '' ? $message : trim($class . ': ' . $message),
            $class,
            isset($row['raw']) && is_string($row['raw']) ? $row['raw'] : null,
            $str($row['description_hash'] ?? ''),
        );
    }
}
