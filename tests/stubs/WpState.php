<?php

declare(strict_types=1);

/**
 * In-memory WordPress state for the stub layer. Options and post meta are
 * kept per blog so multisite behaviour can be tested.
 */
final class WpState
{
    public static int $blog = 1;
    /** @var array<int, array<string, mixed>> blog => options */
    public static array $options = [];
    /** @var array<string, mixed> network options */
    public static array $siteOptions = [];
    /** @var array<int, array<int, WP_Post>> blog => id => post */
    public static array $posts = [];
    /** @var array<int, array<int, array<string, list<mixed>>>> blog => post => key => values */
    public static array $meta = [];
    /** @var array<string, array<int, list<callable>>> */
    public static array $filters = [];
    /** @var list<array{0: string, 1: list<mixed>}> */
    public static array $actions = [];
    /** @var array<int, list<string>> user => caps */
    public static array $caps = [];
    /** @var array<int, list<int>> user => post ids they may edit (when absent: all) */
    public static array $editable = [];
    public static int $user = 0;
    /** @var list<array{time: int, hook: string, args: list<mixed>}> */
    public static array $scheduled = [];
    /** @var list<int> */
    public static array $cleaned = [];
    public static bool $multisite = false;
    public static bool $singular = false;
    public static int $queried = 0;
    /** @var list<string> */
    public static array $metaWrites = [];

    public static function reset(): void
    {
        self::$blog = 1;
        self::$options = [];
        self::$siteOptions = [];
        self::$posts = [];
        self::$meta = [];
        self::$filters = [];
        self::$actions = [];
        self::$caps = [];
        self::$editable = [];
        self::$user = 0;
        self::$scheduled = [];
        self::$cleaned = [];
        self::$multisite = false;
        self::$singular = false;
        self::$queried = 0;
        self::$metaWrites = [];
    }

    /** @param array<string, mixed> $fields */
    public static function addPost(array $fields): WP_Post
    {
        $post = new WP_Post();
        foreach ($fields as $k => $v) {
            $post->$k = $v;
        }
        self::$posts[self::$blog][$post->ID] = $post;

        return $post;
    }

    /** @return list<array{0: string, 1: list<mixed>}> */
    public static function actionsNamed(string $hook): array
    {
        return array_values(array_filter(self::$actions, static fn (array $a): bool => $a[0] === $hook));
    }
}
