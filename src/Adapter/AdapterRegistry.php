<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Adapter;

use IndianOpinion\MetaWriter\Exception\TargetUnavailableException;

/**
 * The shipped adapters and the rule that picks one (PRD case 59).
 */
final class AdapterRegistry
{
    public const FALLBACK = 'fallback';

    /** @var array<string, TargetAdapter>|null */
    private ?array $adapters = null;

    /** @return array<string, TargetAdapter> */
    public static function shipped(): array
    {
        $list = [
            new MetaKeyAdapter(
                'yoast',
                'Yoast SEO',
                '_yoast_wpseo_title',
                '_yoast_wpseo_metadesc',
                static fn (): bool => defined('WPSEO_VERSION'),
                false,
                '/%%[a-z0-9_]+%%/i',
                '%%sep%% %%sitename%%',
            ),
            new MetaKeyAdapter(
                'rankmath',
                'Rank Math',
                'rank_math_title',
                'rank_math_description',
                static fn (): bool => class_exists('RankMath') || defined('RANK_MATH_VERSION'),
                false,
                '/%[a-z0-9_]+(?:\([^)]*\))?%/i',
                '%sep% %sitename%',
            ),
            new MetaKeyAdapter(
                'seopress',
                'SEOPress',
                '_seopress_titles_title',
                '_seopress_titles_desc',
                static fn (): bool => defined('SEOPRESS_VERSION'),
                false,
                '/%%[a-z0-9_]+%%/i',
                '%%sep%% %%sitetitle%%',
            ),
            new MetaKeyAdapter(
                'aioseo',
                'All in One SEO (experimental)',
                '_aioseo_title',
                '_aioseo_description',
                static fn (): bool => defined('AIOSEO_VERSION') || function_exists('aioseo'),
                true,
                '/#[a-z0-9_]+/i',
                '#separator_sa #site_title',
            ),
            new MetaKeyAdapter(
                self::FALLBACK,
                'meta-writer fallback (no SEO plugin)',
                '_meta_writer_title',
                '_meta_writer_description',
                static fn (): bool => true,
            ),
        ];
        $out = [];
        foreach ($list as $adapter) {
            $out[$adapter->id()] = $adapter;
        }

        return $out;
    }

    /** @param array<string, TargetAdapter>|null $adapters */
    public function __construct(?array $adapters = null)
    {
        $this->adapters = $adapters;
    }

    /** @return array<string, TargetAdapter> */
    public function all(): array
    {
        if ($this->adapters === null) {
            $adapters = self::shipped();
            $filtered = apply_filters('meta_writer_adapters', $adapters);
            $this->adapters = [];
            foreach (is_array($filtered) ? $filtered : $adapters as $adapter) {
                if ($adapter instanceof TargetAdapter) {
                    $this->adapters[$adapter->id()] = $adapter;
                }
            }
            if (!isset($this->adapters[self::FALLBACK])) {
                $this->adapters[self::FALLBACK] = self::shipped()[self::FALLBACK];
            }
        }

        return $this->adapters;
    }

    public function get(string $id): ?TargetAdapter
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * SEO plugins detected as active (the fallback is never listed).
     *
     * @return list<TargetAdapter>
     */
    public function detected(): array
    {
        $out = [];
        foreach ($this->all() as $id => $adapter) {
            if ($id !== self::FALLBACK && $adapter->isAvailable()) {
                $out[] = $adapter;
            }
        }

        return $out;
    }

    /**
     * The adapter to use for $target ("auto" or an adapter id).
     *
     * @throws TargetUnavailableException
     */
    public function resolve(string $target): TargetAdapter
    {
        if ($target === 'auto' || $target === '') {
            $detected = $this->detected();
            if (count($detected) > 1) {
                throw new TargetUnavailableException(
                    sprintf('More than one SEO plugin is active (%s); choose a target in the settings.', implode(', ', array_map(static fn (TargetAdapter $a): string => $a->label(), $detected))),
                    'ambiguous',
                );
            }

            return $detected[0] ?? $this->all()[self::FALLBACK];
        }
        $adapter = $this->get($target);
        if ($adapter === null) {
            throw new TargetUnavailableException(sprintf('Unknown target "%s".', $target), 'unknown');
        }
        if (!$adapter->isAvailable()) {
            throw new TargetUnavailableException(sprintf('The target %s is not active.', $adapter->label()), 'inactive');
        }

        return $adapter;
    }
}
