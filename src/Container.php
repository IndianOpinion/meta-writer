<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

use IndianOpinion\MetaWriter\Adapter\AdapterRegistry;
use IndianOpinion\MetaWriter\Caller\CallerFactory;
use IndianOpinion\MetaWriter\Clock\Clock;
use IndianOpinion\MetaWriter\Clock\WpClock;
use IndianOpinion\MetaWriter\Integration\GenQueue;
use IndianOpinion\MetaWriter\Integration\SpendMeter;
use IndianOpinion\MetaWriter\Store\CounterStore;
use IndianOpinion\MetaWriter\Store\PostSource;
use IndianOpinion\MetaWriter\Store\SuggestionStore;
use IndianOpinion\MetaWriter\Store\WpdbCounterStore;
use IndianOpinion\MetaWriter\Store\WpdbPostSource;
use IndianOpinion\MetaWriter\Store\WpdbSuggestionStore;

/**
 * Builds the services once per request. Tests pass their own stores and
 * clock to the constructor.
 */
final class Container
{
    private static ?self $instance = null;

    public readonly Settings $settings;
    public readonly Log $log;
    public readonly Clock $clock;
    public readonly SuggestionStore $suggestions;
    public readonly CounterStore $counters;
    public readonly PostSource $posts;
    public readonly AdapterRegistry $adapters;
    public readonly Provenance $provenance;
    public readonly KillSwitch $kill;
    public readonly DailyCap $cap;
    public readonly Capabilities $caps;
    public readonly Validator $validator;
    public readonly Prompt $prompt;
    public readonly SpendMeter $spend;
    public readonly GenQueue $queue;
    public readonly CallerFactory $callers;
    public readonly Generator $generator;
    public readonly Runner $runner;
    public readonly Reviewer $reviewer;

    public function __construct(
        ?SuggestionStore $suggestions = null,
        ?CounterStore $counters = null,
        ?PostSource $posts = null,
        ?Clock $clock = null,
        ?AdapterRegistry $adapters = null,
    ) {
        $this->settings = new Settings();
        $this->log = new Log();
        $this->clock = $clock ?? new WpClock();
        if ($suggestions === null || $counters === null || $posts === null) {
            global $wpdb;
            $db = $wpdb instanceof \wpdb ? $wpdb : null;
            if ($db === null) {
                throw new \LogicException('meta-writer needs $wpdb or explicit stores.');
            }
            $suggestions ??= new WpdbSuggestionStore($db);
            $counters ??= new WpdbCounterStore($db);
            $posts ??= new WpdbPostSource($db);
        }
        $this->suggestions = $suggestions;
        $this->counters = $counters;
        $this->posts = $posts;
        $this->adapters = $adapters ?? new AdapterRegistry();
        $this->provenance = new Provenance();
        $this->kill = new KillSwitch($this->counters);
        $this->cap = new DailyCap($this->counters, $this->settings, $this->clock);
        $this->caps = new Capabilities($this->settings);
        $this->validator = new Validator($this->settings);
        $this->prompt = new Prompt($this->log);
        $this->spend = new SpendMeter();
        $this->queue = new GenQueue();
        $this->callers = new CallerFactory($this->settings);
        $this->log->addSecret($this->settings->apiKey());
        $this->generator = new Generator(
            $this->settings,
            $this->adapters,
            $this->provenance,
            $this->suggestions,
            $this->kill,
            $this->cap,
            fn () => $this->callers->make(),
            $this->prompt,
            $this->validator,
            $this->spend,
            $this->queue,
            $this->log,
            $this->clock,
        );
        $this->runner = new Runner($this->settings, $this->generator, $this->posts, $this->cap, $this->log, $this->clock);
        $this->reviewer = new Reviewer($this->settings, $this->adapters, $this->provenance, $this->suggestions, $this->caps, $this->validator, $this->log, $this->clock);
    }

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    /** Replace the shared instance (tests, multisite switch_to_blog). */
    public static function set(?self $container): void
    {
        self::$instance = $container;
    }

    /** Can generation run at all? Library or custom caller present, and configured. */
    public function canGenerate(): bool
    {
        return $this->generator->caller() !== null;
    }
}
