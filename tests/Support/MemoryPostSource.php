<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Support;

use IndianOpinion\MetaWriter\Store\PostSource;

final class MemoryPostSource implements PostSource
{
    public int $calls = 0;

    public function next(array $types, array $statuses, ?string $cursor, int $limit, string $order): array
    {
        $this->calls++;
        $posts = array_values(array_filter(\WpState::$posts[\WpState::$blog] ?? [], static fn (\WP_Post $p): bool => in_array($p->post_type, $types, true) && in_array($p->post_status, $statuses, true) && $p->post_password === ''));
        if ($order === self::ORDER_NEWEST) {
            usort($posts, static fn (\WP_Post $a, \WP_Post $b): int => [$b->post_date, $b->ID] <=> [$a->post_date, $a->ID]);
            if ($cursor !== null && str_contains($cursor, '|')) {
                [$date, $id] = explode('|', $cursor, 2);
                $posts = array_values(array_filter($posts, static fn (\WP_Post $p): bool => [$p->post_date, $p->ID] < [$date, (int) $id]));
            }
        } else {
            usort($posts, static fn (\WP_Post $a, \WP_Post $b): int => $a->ID <=> $b->ID);
            if ($cursor !== null && $cursor !== '') {
                $posts = array_values(array_filter($posts, static fn (\WP_Post $p): bool => $p->ID > (int) $cursor));
            }
        }
        $out = [];
        foreach (array_slice($posts, 0, $limit) as $p) {
            $out[] = ['id' => $p->ID, 'cursor' => $order === self::ORDER_NEWEST ? $p->post_date . '|' . $p->ID : (string) $p->ID];
        }

        return $out;
    }
}
