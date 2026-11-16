<?php

declare(strict_types=1);

/**
 * A mysqli-backed wpdb with the subset of methods meta-writer uses. Only
 * the database tests load a real connection.
 */
class wpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $sitemeta = 'wp_sitemeta';
    public int $insert_id = 0;
    public int $rows_affected = 0;
    public string $last_error = '';

    public function __construct(public mysqli $dbh, string $prefix = 'wp_')
    {
        $this->prefix = $prefix;
        $this->options = $prefix . 'options';
        $this->posts = $prefix . 'posts';
        $this->postmeta = $prefix . 'postmeta';
        $this->sitemeta = $prefix . 'sitemeta';
    }

    public function prepare(string $query, mixed ...$args): ?string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        $out = (string) preg_replace_callback('/%%|%[dsf]/', function (array $m) use (&$i, $args): string {
            if ($m[0] === '%%') {
                return '%';
            }
            $v = $args[$i++] ?? null;

            return match ($m[0]) {
                '%d' => (string) (int) $v,
                '%f' => (string) (float) $v,
                default => "'" . $this->dbh->real_escape_string((string) $v) . "'",
            };
        }, $query);

        return $out;
    }

    public function query(string $sql): int|bool
    {
        $this->last_error = '';
        try {
            $result = $this->dbh->query($sql);
        } catch (mysqli_sql_exception $e) {
            $this->last_error = $e->getMessage();

            return false;
        }
        if ($result === false) {
            $this->last_error = $this->dbh->error;

            return false;
        }
        if ($result instanceof mysqli_result) {
            $n = $result->num_rows;
            $result->free();

            return $n;
        }
        $this->rows_affected = (int) $this->dbh->affected_rows;
        $this->insert_id = (int) $this->dbh->insert_id;

        return $this->rows_affected;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $result = $this->dbh->query($sql);
        if (!$result instanceof mysqli_result) {
            return [];
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        return $rows;
    }

    public function get_var(?string $sql): ?string
    {
        $rows = $this->rows((string) $sql);
        if ($rows === []) {
            return null;
        }
        $v = array_values($rows[0])[0] ?? null;

        return $v === null ? null : (string) $v;
    }

    public function get_row(?string $sql, string $output = 'OBJECT'): ?array
    {
        return $this->rows((string) $sql)[0] ?? null;
    }

    public function get_results(?string $sql, string $output = 'OBJECT'): array
    {
        return $this->rows((string) $sql);
    }

    public function get_col(?string $sql, int $x = 0): array
    {
        return array_map(static fn (array $r) => array_values($r)[$x], $this->rows((string) $sql));
    }

    public function insert(string $table, array $data, mixed $format = null): int|false
    {
        $cols = implode(', ', array_map(static fn ($c) => '`' . $c . '`', array_keys($data)));
        $vals = implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : "'" . $this->dbh->real_escape_string((string) $v) . "'", array_values($data)));
        $r = $this->query("INSERT INTO $table ($cols) VALUES ($vals)");

        return $r === false ? false : (int) $r;
    }

    public function delete(string $table, array $where, mixed $format = null): int|false
    {
        $conds = [];
        foreach ($where as $k => $v) {
            $conds[] = '`' . $k . "` = '" . $this->dbh->real_escape_string((string) $v) . "'";
        }
        $r = $this->query("DELETE FROM $table WHERE " . implode(' AND ', $conds));

        return $r === false ? false : (int) $r;
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
}
