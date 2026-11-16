<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Store;

/**
 * One option per day, meta_writer_calls_<Y-m-d>, autoload off. The take is
 * an insert-if-absent followed by one conditional UPDATE, so two processes
 * can never both take the last call (PRD 5.7).
 */
final class WpdbCounterStore implements CounterStore
{
    public const PREFIX = 'meta_writer_calls_';

    public function __construct(private readonly \wpdb $db)
    {
    }

    public static function optionName(string $day): string
    {
        return self::PREFIX . $day;
    }

    public function increment(string $day, int $cap): bool
    {
        if ($cap <= 0) {
            return false;
        }
        $name = self::optionName($day);
        $this->db->query((string) $this->db->prepare(
            'INSERT IGNORE INTO ' . $this->db->options . " (option_name, option_value, autoload) VALUES (%s, '0', 'off')",
            $name,
        ));
        $updated = $this->db->query((string) $this->db->prepare(
            'UPDATE ' . $this->db->options . ' SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d',
            $name,
            $cap,
        ));
        // Keep WordPress's own cache honest for anyone reading with get_option().
        wp_cache_delete($name, 'options');

        return is_int($updated) && $updated === 1;
    }

    public function get(string $day): int
    {
        $value = $this->db->get_var($this->db->prepare('SELECT option_value FROM ' . $this->db->options . ' WHERE option_name = %s', self::optionName($day)));

        return is_numeric($value) ? (int) $value : 0;
    }

    public function purgeBefore(string $day): int
    {
        $rows = $this->db->get_col($this->db->prepare(
            'SELECT option_name FROM ' . $this->db->options . ' WHERE option_name LIKE %s',
            $this->db->esc_like(self::PREFIX) . '%',
        ));
        $removed = 0;
        foreach (is_array($rows) ? $rows : [] as $name) {
            $name = (string) $name;
            $date = substr($name, strlen(self::PREFIX));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strcmp($date, $day) < 0) {
                $this->db->delete($this->db->options, ['option_name' => $name], ['%s']);
                wp_cache_delete($name, 'options');
                $removed++;
            }
        }

        return $removed;
    }

    public function freshOption(string $name): ?string
    {
        $value = $this->db->get_var($this->db->prepare('SELECT option_value FROM ' . $this->db->options . ' WHERE option_name = %s LIMIT 1', $name));

        return $value === null ? null : (string) $value;
    }

    public function freshNetworkOption(string $name): ?string
    {
        if (!is_multisite() || !isset($this->db->sitemeta)) {
            return null;
        }
        $value = $this->db->get_var($this->db->prepare(
            'SELECT meta_value FROM ' . $this->db->sitemeta . ' WHERE meta_key = %s AND site_id = %d LIMIT 1',
            $name,
            get_current_network_id(),
        ));

        return $value === null ? null : (string) $value;
    }
}
