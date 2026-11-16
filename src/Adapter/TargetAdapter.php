<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Adapter;

/**
 * Where a field is written. Adapters read and write post meta only,
 * through the WordPress meta API (PRD case 61).
 */
interface TargetAdapter
{
    public function id(): string;

    public function label(): string;

    /** Is the SEO plugin this adapter writes for active? */
    public function isAvailable(): bool;

    /** Experimental adapters may not show on the page; the screen says so. */
    public function isExperimental(): bool;

    public function read(int $postId, string $field): string;

    public function write(int $postId, string $field, string $value): void;

    /** Used only by revert. */
    public function clear(int $postId, string $field): void;

    /** The plugin's own separator and site-name syntax, for documentation only. */
    public function suffixHint(): string;

    /**
     * Does $value consist only of this plugin's template variables? Used
     * only when the operator opts in to treating such values as empty.
     */
    public function isTemplateOnly(string $value): bool;
}
