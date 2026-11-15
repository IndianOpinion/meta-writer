<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

/**
 * Settings: defaults, constant overrides and validation.
 *
 * Every setting may be fixed by a constant named META_WRITER_ plus the
 * upper-cased key (for example META_WRITER_DAILY_CAP). The API key reads
 * META_WRITER_API_KEY. Invalid values are rejected on save with a message,
 * never silently clamped (PRD section 7).
 */
final class Settings
{
    public const OPTION = 'meta_writer_settings';

    public const TITLE_RANGE = [10, 120];
    public const DESCRIPTION_RANGE = [50, 320];
    public const BODY_RANGE = [500, 20000];
    public const CAP_RANGE = [0, 100000];

    /** Post types that can never be enabled. */
    public const FORBIDDEN_TYPES = ['revision', 'nav_menu_item', 'attachment', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face'];

    /** Statuses that can never be enabled (revisions and autosaves use inherit). */
    public const FORBIDDEN_STATUSES = ['inherit', 'auto-draft', 'trash'];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $defaults = [
            'target' => 'auto',
            'fields' => 'title,description',
            'post_types' => 'post,page',
            'post_statuses' => 'publish,future',
            'title_min' => 30,
            'title_max' => 60,
            'description_min' => 70,
            'description_max' => 155,
            'title_suffix' => '',
            'body_budget' => 6000,
            'min_words' => 40,
            'daily_cap' => 200,
            'on_publish' => false,
            'on_publish_delay' => 60,
            'use_queue' => true,
            'allow_authors' => false,
            'keep_raw' => false,
            'retention_days' => 90,
            'cron_time_budget' => 50,
            'batch_size' => 100,
            'template_as_empty' => false,
            'remove_fallback_on_uninstall' => false,
            'provider' => 'openrouter',
            'model' => '',
            'fallback_model' => '',
            'base_url' => '',
            'api_key' => '',
        ];

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('meta_writer_default_settings', $defaults);
            if (is_array($filtered)) {
                $defaults = array_merge($defaults, array_intersect_key($filtered, $defaults));
            }
        }

        return $defaults;
    }

    /** Clear the per-request cache after a save. */
    public function flush(): void
    {
        $this->cache = null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $values = array_merge(self::defaults(), array_intersect_key($stored, self::defaults()));
        foreach (array_keys($values) as $key) {
            $constant = self::constantName($key);
            if (defined($constant)) {
                $values[$key] = constant($constant);
            }
        }
        $this->cache = $values;

        return $values;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function int(string $key): int
    {
        $v = $this->get($key);

        return is_numeric($v) ? (int) $v : 0;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function string(string $key): string
    {
        $v = $this->get($key);

        return is_scalar($v) ? (string) $v : '';
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $v = $this->get($key);
        $parts = is_array($v) ? $v : explode(',', (string) (is_scalar($v) ? $v : ''));

        return array_values(array_filter(array_map(static fn ($p): string => trim((string) $p), $parts), static fn (string $p): bool => $p !== ''));
    }

    public static function constantName(string $key): string
    {
        return 'META_WRITER_' . strtoupper($key);
    }

    /** Is this key fixed by a constant? */
    public function fixedByConstant(string $key): bool
    {
        return defined(self::constantName($key));
    }

    /** The API key, preferring the constant (never stored when the constant is used). */
    public function apiKey(): string
    {
        return $this->string('api_key');
    }

    /** "…abcd": the last four characters only. */
    public static function maskKey(string $key): string
    {
        if ($key === '') {
            return '';
        }

        return "\u{2026}" . (strlen($key) > 4 ? substr($key, -4) : '');
    }

    /**
     * Validate submitted values. Returns the values to store and a list of
     * messages for every rejected field. A rejected field keeps its old value.
     *
     * @param array<string, mixed>       $input
     * @param list<string>|null          $publicTypes public post types, or null to skip that check
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validate(array $input, ?array $publicTypes = null): array
    {
        $current = get_option(self::OPTION, []);
        $current = is_array($current) ? $current : [];
        $out = array_merge(self::defaults(), array_intersect_key($current, self::defaults()));
        $errors = [];

        $ints = [
            'title_min' => self::TITLE_RANGE,
            'title_max' => self::TITLE_RANGE,
            'description_min' => self::DESCRIPTION_RANGE,
            'description_max' => self::DESCRIPTION_RANGE,
            'body_budget' => self::BODY_RANGE,
            'min_words' => [1, 10000],
            'daily_cap' => self::CAP_RANGE,
            'on_publish_delay' => [0, 86400],
            'retention_days' => [1, 3650],
            'cron_time_budget' => [5, 600],
            'batch_size' => [1, 1000],
        ];
        foreach ($ints as $key => [$min, $max]) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $raw = is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
            if ($raw === '' || preg_match('/^-?\d+$/', $raw) !== 1) {
                $errors[$key] = sprintf('%s must be a whole number.', $key);
                continue;
            }
            $value = (int) $raw;
            if ($value < $min || $value > $max) {
                $errors[$key] = sprintf('%s must be between %d and %d; %d was not saved.', $key, $min, $max, $value);
                continue;
            }
            $out[$key] = $value;
        }
        foreach ([['title_min', 'title_max'], ['description_min', 'description_max']] as [$lo, $hi]) {
            if (!isset($errors[$lo]) && !isset($errors[$hi]) && (int) $out[$lo] > (int) $out[$hi]) {
                $errors[$lo] = sprintf('%s must not be larger than %s.', $lo, $hi);
                $out[$lo] = $current[$lo] ?? self::defaults()[$lo];
                $out[$hi] = $current[$hi] ?? self::defaults()[$hi];
            }
        }

        foreach (['on_publish', 'use_queue', 'allow_authors', 'keep_raw', 'template_as_empty', 'remove_fallback_on_uninstall'] as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = in_array($input[$key], [true, 1, '1', 'on', 'yes', 'true'], true);
            }
        }

        if (array_key_exists('target', $input)) {
            $target = is_string($input['target']) ? sanitize_key($input['target']) : '';
            if ($target === '' || preg_match('/^[a-z0-9_-]+$/', $target) !== 1) {
                $errors['target'] = 'target must be auto or an adapter id.';
            } else {
                $out['target'] = $target;
            }
        }

        if (array_key_exists('fields', $input)) {
            try {
                $fields = Field::parseList(is_array($input['fields']) ? array_map('strval', array_values($input['fields'])) : (string) (is_scalar($input['fields']) ? $input['fields'] : ''));
                $out['fields'] = implode(',', $fields);
            } catch (Exception\InvalidException $e) {
                $errors['fields'] = $e->getMessage();
            }
        }

        if (array_key_exists('post_types', $input)) {
            $types = self::csv($input['post_types']);
            $bad = array_values(array_filter($types, static fn (string $t): bool => in_array($t, self::FORBIDDEN_TYPES, true) || ($publicTypes !== null && !in_array($t, $publicTypes, true))));
            if ($types === []) {
                $errors['post_types'] = 'Choose at least one post type.';
            } elseif ($bad !== []) {
                $errors['post_types'] = sprintf('Not allowed (public types only, never revisions): %s.', implode(', ', $bad));
            } else {
                $out['post_types'] = implode(',', $types);
            }
        }

        if (array_key_exists('post_statuses', $input)) {
            $statuses = self::csv($input['post_statuses']);
            $bad = array_values(array_intersect($statuses, self::FORBIDDEN_STATUSES));
            if ($statuses === []) {
                $errors['post_statuses'] = 'Choose at least one status.';
            } elseif ($bad !== []) {
                $errors['post_statuses'] = sprintf('Not allowed (revisions and autosaves are never candidates): %s.', implode(', ', $bad));
            } else {
                $out['post_statuses'] = implode(',', $statuses);
            }
        }

        if (array_key_exists('title_suffix', $input)) {
            $suffix = is_scalar($input['title_suffix']) ? sanitize_text_field((string) $input['title_suffix']) : '';
            if (mb_strlen($suffix) > 60) {
                $errors['title_suffix'] = 'title_suffix must be 60 characters or fewer.';
            } else {
                $out['title_suffix'] = $suffix;
            }
        }

        if (array_key_exists('provider', $input)) {
            $provider = is_string($input['provider']) ? $input['provider'] : '';
            if (!in_array($provider, ['openrouter', 'openai', 'generic'], true)) {
                $errors['provider'] = 'provider must be openrouter, openai or generic.';
            } else {
                $out['provider'] = $provider;
            }
        }

        foreach (['model', 'fallback_model'] as $key) {
            if (array_key_exists($key, $input)) {
                $model = is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
                if ($model !== '' && preg_match('#^[A-Za-z0-9._:/@+-]{1,200}$#', $model) !== 1) {
                    $errors[$key] = sprintf('%s has characters a model id never has.', $key);
                } else {
                    $out[$key] = $model;
                }
            }
        }

        if (array_key_exists('base_url', $input)) {
            $url = is_scalar($input['base_url']) ? trim((string) $input['base_url']) : '';
            if ($url !== '' && preg_match('#^https?://[^\s]+$#', $url) !== 1) {
                $errors['base_url'] = 'base_url must be an http or https URL.';
            } else {
                $out['base_url'] = $url;
            }
        }

        // The key: an empty submission keeps the stored key; the constant wins and is never stored.
        if (array_key_exists('api_key', $input)) {
            $key = is_scalar($input['api_key']) ? trim((string) $input['api_key']) : '';
            if ($this->fixedByConstant('api_key')) {
                $out['api_key'] = '';
            } elseif ($key !== '') {
                if (preg_match('/^[\x21-\x7e]{8,512}$/', $key) !== 1) {
                    $errors['api_key'] = 'The API key looks malformed and was not saved.';
                } else {
                    $out['api_key'] = $key;
                }
            }
        }
        if (!empty($input['api_key_clear'])) {
            $out['api_key'] = '';
        }

        return [$out, $errors];
    }

    /** @return list<string> */
    private static function csv(mixed $value): array
    {
        $parts = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');
        $out = [];
        foreach ($parts as $part) {
            $part = sanitize_key(is_scalar($part) ? (string) $part : '');
            if ($part !== '') {
                $out[$part] = true;
            }
        }

        return array_keys($out);
    }
}
