<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Store;

/**
 * Indexed keyset paging over wp_posts: no offset, no unbounded query.
 */
final class WpdbPostSource implements PostSource
{
    public function __construct(private readonly \wpdb $db)
    {
    }

    public function next(array $types, array $statuses, ?string $cursor, int $limit, string $order): array
    {
        if ($types === [] || $statuses === []) {
            return [];
        }
        $limit = max(1, $limit);
        $params = array_merge($types, $statuses);
        $sql = 'SELECT ID, post_date FROM ' . $this->db->posts
            . ' WHERE post_type IN (' . implode(',', array_fill(0, count($types), '%s')) . ')'
            . ' AND post_status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')'
            . " AND post_password = ''";
        if ($order === self::ORDER_NEWEST) {
            if ($cursor !== null && str_contains($cursor, '|')) {
                [$date, $id] = explode('|', $cursor, 2);
                $sql .= ' AND (post_date < %s OR (post_date = %s AND ID < %d))';
                array_push($params, $date, $date, (int) $id);
            }
            $sql .= ' ORDER BY post_date DESC, ID DESC LIMIT %d';
        } else {
            if ($cursor !== null && $cursor !== '') {
                $sql .= ' AND ID > %d';
                $params[] = (int) $cursor;
            }
            $sql .= ' ORDER BY ID ASC LIMIT %d';
        }
        $params[] = $limit;
        $rows = $this->db->get_results($this->db->prepare($sql, ...$params), ARRAY_A);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) $row['ID'];
            $out[] = [
                'id' => $id,
                'cursor' => $order === self::ORDER_NEWEST ? $row['post_date'] . '|' . $id : (string) $id,
            ];
        }

        return $out;
    }
}
