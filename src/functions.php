<?php

/**
 * Public functions (PRD 5.1). Thin wrappers over IndianOpinion\MetaWriter\Api.
 */

declare(strict_types=1);

use IndianOpinion\MetaWriter\AcceptResult;
use IndianOpinion\MetaWriter\Api;
use IndianOpinion\MetaWriter\Container;
use IndianOpinion\MetaWriter\FitResult;
use IndianOpinion\MetaWriter\Suggestion;

if (!function_exists('meta_writer_generate')) {
    /**
     * Generate a suggestion for one post. Never writes a target.
     *
     * @param array{fields?: string|list<string>, regenerate?: bool, refresh_own?: bool, dry_run?: bool} $args
     */
    function meta_writer_generate(int $post_id, array $args = []): Suggestion|\WP_Error
    {
        return Api::generate($post_id, $args);
    }

    function meta_writer_get_suggestion(int $post_id): ?Suggestion
    {
        return Container::get()->suggestions->latestForPost($post_id);
    }

    /**
     * @param array{title?: string, description?: string} $edits
     * @param array{confirm_stale?: bool}                  $args
     */
    function meta_writer_accept(int $suggestion_id, array $edits = [], array $args = []): AcceptResult|\WP_Error
    {
        return Api::accept($suggestion_id, $edits, $args);
    }

    function meta_writer_reject(int $suggestion_id): bool|\WP_Error
    {
        return Api::reject($suggestion_id);
    }

    function meta_writer_revert(int $post_id, string $field): bool|\WP_Error
    {
        return Api::revert($post_id, $field);
    }

    function meta_writer_is_manual(int $post_id, string $field): bool
    {
        return Api::isManual($post_id, $field);
    }

    function meta_writer_measure(string $text): int
    {
        return Api::measure($text);
    }

    function meta_writer_fit(string $text, int $min, int $max, string $field): FitResult
    {
        return Api::fit($text, $min, $max, $field);
    }

    function meta_writer_is_enabled(): bool
    {
        return Api::isEnabled();
    }

    function meta_writer_cap_remaining(): int
    {
        return Api::capRemaining();
    }
}
