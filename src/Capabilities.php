<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

/**
 * Who may do what (PRD case 42). Every capability is filterable through
 * meta_writer_capability.
 */
final class Capabilities
{
    public const GENERATE = 'generate';
    public const REVIEW = 'review';
    public const SETTINGS = 'settings';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function capability(string $action): string
    {
        $authors = $this->settings->bool('allow_authors');
        $default = match ($action) {
            self::SETTINGS => 'manage_options',
            self::REVIEW, self::GENERATE => $authors ? 'edit_posts' : 'edit_others_posts',
            default => 'manage_options',
        };
        $cap = apply_filters('meta_writer_capability', $default, $action);

        return is_string($cap) && $cap !== '' ? $cap : $default;
    }

    /** May $userId do $action, on $postId when given (which also needs edit_post)? */
    public function can(int $userId, string $action, ?int $postId = null): bool
    {
        if ($userId <= 0 || !user_can($userId, $this->capability($action))) {
            return false;
        }

        return $postId === null || user_can($userId, 'edit_post', $postId);
    }
}
