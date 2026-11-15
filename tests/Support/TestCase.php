<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Support;

use IndianOpinion\MetaWriter\Container;
use IndianOpinion\MetaWriter\Settings;
use IndianOpinion\MetaWriter\Suggestion;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const ADMIN = 1;
    public const EDITOR = 2;
    public const AUTHOR = 3;

    protected Container $c;
    protected FakeCaller $caller;
    protected MemorySuggestionStore $store;
    protected MemoryCounterStore $counters;
    protected MemoryPostSource $source;
    protected TestClock $clock;

    protected function setUp(): void
    {
        \WpState::reset();
        \WP_CLI::reset();
        \WpState::$caps = [
            self::ADMIN => ['manage_options', 'edit_others_posts', 'edit_posts'],
            self::EDITOR => ['edit_others_posts', 'edit_posts'],
            self::AUTHOR => ['edit_posts'],
        ];
        \WpState::$editable[self::AUTHOR] = [];
        \WpState::$user = self::EDITOR;
        update_option(Settings::OPTION, ['model' => 'test/model', 'api_key' => 'test-key-0123456789']);
        $this->caller = new FakeCaller();
        $caller = $this->caller;
        add_filter('meta_writer_caller', static fn () => $caller);
        $this->rebuild();
    }

    protected function tearDown(): void
    {
        Container::set(null);
    }

    protected function rebuild(): void
    {
        $this->store ??= new MemorySuggestionStore();
        $this->counters ??= new MemoryCounterStore();
        $this->source ??= new MemoryPostSource();
        $this->clock ??= new TestClock();
        $this->c = new Container($this->store, $this->counters, $this->source, $this->clock);
        Container::set($this->c);
    }

    /** @param array<string, mixed> $values */
    protected function settings(array $values): void
    {
        $current = get_option(Settings::OPTION, []);
        update_option(Settings::OPTION, array_merge(is_array($current) ? $current : [], $values));
        $this->rebuild();
    }

    public static function body(int $words = 60): string
    {
        $base = ['The', 'monsoon', 'reached', 'the', 'Kerala', 'coast', 'three', 'days', 'early', 'this', 'year', 'and', 'farmers', 'in', 'several', 'districts', 'welcomed', 'the', 'heavy', 'rain.'];
        $out = [];
        for ($i = 0; $i < $words; $i++) {
            $out[] = $base[$i % count($base)];
        }

        return '<p>' . implode(' ', $out) . '</p>';
    }

    /** @param array<string, mixed> $fields */
    protected function post(int $id, array $fields = []): \WP_Post
    {
        return \WpState::addPost($fields + [
            'ID' => $id,
            'post_title' => 'Monsoon reaches Kerala early',
            'post_content' => self::body(),
            'post_date' => sprintf('2026-01-%02d 10:00:00', ($id % 28) + 1),
        ]);
    }

    protected function suggestFor(int $postId, array $args = []): Suggestion
    {
        $o = $this->c->generator->generate($postId, $args + ['dry_run' => false]);
        $this->assertNotNull($o->suggestion, 'expected a suggestion, got ' . $o->kind . ' ' . $o->reason);

        return $o->suggestion;
    }

    protected function meta(int $postId, string $key): mixed
    {
        return get_post_meta($postId, $key, true);
    }
}
