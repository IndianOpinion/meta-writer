<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

use IndianOpinion\MetaWriter\Text\Cleaner;

/**
 * The text a suggestion is made from (cases 10 to 15). Only the title,
 * the manual excerpt and the cleaned body: never author data, comments or
 * custom fields.
 */
final class Input
{
    public function __construct(
        public readonly string $title,
        public readonly string $excerpt,
        public readonly string $body,
        public readonly string $language,
        public readonly int $words,
    ) {
    }

    public static function fromPost(\WP_Post $post, Settings $settings): self
    {
        $title = Cleaner::collapse(Cleaner::decode(wp_strip_all_tags((string) $post->post_title)));
        $excerpt = Cleaner::clean((string) $post->post_excerpt);
        $body = Cleaner::clean((string) $post->post_content);
        $filtered = apply_filters('meta_writer_clean_input', $body, $post);
        if (is_string($filtered)) {
            $body = $filtered;
        }
        $words = Cleaner::wordCount($body);
        $body = Cleaner::budget($body, $settings->int('body_budget'));

        $locale = function_exists('get_locale') ? (string) get_locale() : 'en_US';
        $language = apply_filters('meta_writer_post_language', str_replace('_', '-', $locale), $post);
        $language = is_string($language) && preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language) === 1 ? $language : str_replace('_', '-', $locale);

        return new self($title, $excerpt, $body, $language, $words);
    }

    public function hash(): string
    {
        return hash('sha256', (string) wp_json_encode([$this->title, $this->excerpt, $this->body, $this->language], JSON_UNESCAPED_UNICODE));
    }

    public function length(): int
    {
        return mb_strlen($this->body, 'UTF-8');
    }

    /** Everything the model saw, for the unsupported-detail check. */
    public function haystack(): string
    {
        return $this->title . "\n" . $this->excerpt . "\n" . $this->body;
    }
}
