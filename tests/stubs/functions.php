<?php

declare(strict_types=1);

// A small WordPress stub layer: enough for meta-writer's code paths.

function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    WpState::$filters[$hook][$priority][] = $cb;

    return true;
}

function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $cb, $priority, $args);
}

function has_filter(string $hook, mixed $cb = false): bool|int
{
    return !empty(WpState::$filters[$hook]);
}

function has_action(string $hook, mixed $cb = false): bool|int
{
    return has_filter($hook, $cb);
}

function remove_all_filters(string $hook): bool
{
    unset(WpState::$filters[$hook]);

    return true;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    $byPriority = WpState::$filters[$hook] ?? [];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as $cb) {
            $value = $cb($value, ...$args);
        }
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    WpState::$actions[] = [$hook, $args];
    $byPriority = WpState::$filters[$hook] ?? [];
    ksort($byPriority);
    foreach ($byPriority as $callbacks) {
        foreach ($callbacks as $cb) {
            $cb(...$args);
        }
    }
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpState::$options[WpState::$blog] ?? []) ? WpState::$options[WpState::$blog][$name] : $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WpState::$options[WpState::$blog][$name] = $value;

    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = 'yes'): bool
{
    if (array_key_exists($name, WpState::$options[WpState::$blog] ?? [])) {
        return false;
    }
    WpState::$options[WpState::$blog][$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset(WpState::$options[WpState::$blog][$name]);

    return true;
}

function get_site_option(string $name, mixed $default = false): mixed
{
    return WpState::$siteOptions[$name] ?? $default;
}

function update_site_option(string $name, mixed $value): bool
{
    WpState::$siteOptions[$name] = $value;

    return true;
}

function delete_site_option(string $name): bool
{
    unset(WpState::$siteOptions[$name]);

    return true;
}

function get_transient(string $name): mixed
{
    return get_option('_transient_' . $name);
}

function set_transient(string $name, mixed $value, int $ttl = 0): bool
{
    return update_option('_transient_' . $name, $value);
}

function delete_transient(string $name): bool
{
    return delete_option('_transient_' . $name);
}

function get_post(mixed $post = null): ?WP_Post
{
    if ($post instanceof WP_Post) {
        return $post;
    }

    return WpState::$posts[WpState::$blog][(int) $post] ?? null;
}

function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    $values = WpState::$meta[WpState::$blog][$id][$key] ?? [];

    return $single ? ($values[0] ?? '') : $values;
}

function update_post_meta(int $id, string $key, mixed $value, mixed $prev = ''): bool
{
    $value = wp_unslash($value);
    WpState::$meta[WpState::$blog][$id][$key] = [$value];
    WpState::$metaWrites[] = $id . ':' . $key;

    return true;
}

function delete_post_meta(int $id, string $key, mixed $value = ''): bool
{
    unset(WpState::$meta[WpState::$blog][$id][$key]);
    WpState::$metaWrites[] = $id . ':' . $key . ':deleted';

    return true;
}

function delete_post_meta_by_key(string $key): bool
{
    foreach (WpState::$meta[WpState::$blog] ?? [] as $id => $keys) {
        unset(WpState::$meta[WpState::$blog][$id][$key]);
    }

    return true;
}

function wp_slash(mixed $value): mixed
{
    return is_string($value) ? addslashes($value) : $value;
}

function wp_unslash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }

    return is_string($value) ? stripslashes($value) : $value;
}

function wp_is_post_revision(mixed $post): int|false
{
    $p = get_post($post);

    return $p !== null && $p->post_type === 'revision' ? $p->post_parent : false;
}

function wp_is_post_autosave(mixed $post): int|false
{
    $p = get_post($post);

    return $p !== null && $p->post_type === 'revision' && str_contains($p->post_name, 'autosave') ? $p->post_parent : false;
}

function wp_strip_all_tags(string $text): string
{
    $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);

    return trim(strip_tags($text));
}

function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false
{
    return json_encode($data, $flags, $depth);
}

function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function sanitize_text_field(string $text): string
{
    return trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags($text)));
}

function user_can(mixed $user, string $cap, mixed ...$args): bool
{
    $id = (int) $user;
    $caps = WpState::$caps[$id] ?? [];
    if ($cap === 'edit_post') {
        $postId = (int) ($args[0] ?? 0);
        if (!in_array('edit_posts', $caps, true)) {
            return false;
        }
        if (isset(WpState::$editable[$id])) {
            return in_array($postId, WpState::$editable[$id], true);
        }

        return true;
    }

    return in_array($cap, $caps, true);
}

function current_user_can(string $cap, mixed ...$args): bool
{
    return user_can(WpState::$user, $cap, ...$args);
}

function get_current_user_id(): int
{
    return WpState::$user;
}

function clean_post_cache(mixed $post): void
{
    WpState::$cleaned[] = (int) ($post instanceof WP_Post ? $post->ID : $post);
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    return true;
}

function is_multisite(): bool
{
    return WpState::$multisite;
}

function get_current_network_id(): int
{
    return 1;
}

function switch_to_blog(int $id): bool
{
    WpState::$blog = $id;

    return true;
}

function restore_current_blog(): bool
{
    WpState::$blog = 1;

    return true;
}

/** @return list<int> */
function get_sites(array $args = []): array
{
    $ids = array_keys(WpState::$options + WpState::$posts);
    sort($ids);

    return array_values(array_unique(array_map('intval', $ids)));
}

function get_locale(): string
{
    $locale = get_option('WPLANG', '');

    return is_string($locale) && $locale !== '' ? $locale : 'en_US';
}

function wp_timezone(): DateTimeZone
{
    $tz = get_option('timezone_string', 'UTC');

    return new DateTimeZone(is_string($tz) && $tz !== '' ? $tz : 'UTC');
}

function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $tz = null): string|false
{
    $d = new DateTimeImmutable('@' . ($timestamp ?? time()));

    return $d->setTimezone($tz ?? wp_timezone())->format($format);
}

function home_url(string $path = ''): string
{
    return 'https://example.org' . $path;
}

function wp_next_scheduled(string $hook, array $args = []): int|false
{
    foreach (WpState::$scheduled as $e) {
        if ($e['hook'] === $hook && $e['args'] === $args) {
            return $e['time'];
        }
    }

    return false;
}

function wp_schedule_single_event(int $time, string $hook, array $args = []): bool
{
    WpState::$scheduled[] = ['time' => $time, 'hook' => $hook, 'args' => array_values($args)];

    return true;
}

function wp_schedule_event(int $time, string $recurrence, string $hook, array $args = []): bool
{
    return wp_schedule_single_event($time, $hook, $args);
}

function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    $before = count(WpState::$scheduled);
    WpState::$scheduled = array_values(array_filter(WpState::$scheduled, static fn (array $e): bool => $e['hook'] !== $hook));

    return $before - count(WpState::$scheduled);
}

function wp_unschedule_hook(string $hook): int
{
    return wp_clear_scheduled_hook($hook);
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function is_singular(mixed $types = ''): bool
{
    return WpState::$singular;
}

function get_queried_object_id(): int
{
    return WpState::$queried;
}

function get_post_types(array $args = [], string $output = 'names'): array
{
    return ['post' => 'post', 'page' => 'page'];
}

function dbDelta(string $sql): array
{
    global $wpdb;
    $wpdb->query((string) preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql));

    return [];
}

function is_admin(): bool
{
    return false;
}
