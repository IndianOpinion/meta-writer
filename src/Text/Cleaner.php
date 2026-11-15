<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Text;

/**
 * Turns post content into plain text for the prompt (PRD cases 10 to 13).
 *
 * Shortcodes are stripped by pattern, never rendered, so no shortcode code
 * runs during generation.
 */
final class Cleaner
{
    private function __construct()
    {
    }

    public static function clean(string $content): string
    {
        $text = $content;
        // Block comments and every other HTML comment.
        $text = (string) preg_replace('/<!--.*?-->/su', ' ', $text);
        // Elements whose content is never prose.
        $text = (string) preg_replace('#<(script|style|figcaption|noscript|template|svg|iframe)\b[^>]*>.*?</\1\s*>#isu', ' ', $text);
        // Caption shortcodes and their content.
        $text = (string) preg_replace('#\[(caption|wp_caption|gallery|embed|audio|video|playlist)\b[^\]]*\].*?\[/\1\]#isu', ' ', $text);
        // Every other shortcode tag, opening, closing or self-closing; enclosed text stays.
        $text = (string) preg_replace('#\[\[?/?[A-Za-z][\w-]*(?:\s[^\[\]]*)?/?\]?\]#u', ' ', $text);
        // Tags, with a space so words in adjacent blocks do not glue together.
        $text = (string) preg_replace('/<[^>]*>/u', ' ', $text);
        $text = self::decode($text);

        return self::collapse($text);
    }

    /** Decode HTML entities to UTF-8 (twice, for double-escaped titles). */
    public static function decode(string $text): string
    {
        for ($i = 0; $i < 2 && str_contains($text, '&'); $i++) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $text;
    }

    /** Control characters and every kind of space to one space, trimmed. */
    public static function collapse(string $text): string
    {
        $text = (string) preg_replace('/[\p{Cc}\p{Z}\x{FEFF}]+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Cut to a budget in characters at a sentence or word boundary, never
     * inside a multibyte character.
     */
    public static function budget(string $text, int $budget): string
    {
        if ($budget <= 0 || mb_strlen($text, 'UTF-8') <= $budget) {
            return $text;
        }
        $cut = mb_substr($text, 0, $budget, 'UTF-8');
        // Prefer the last sentence end in the second half of the budget.
        if (preg_match_all('/[.!?\x{0964}\x{0965}\x{3002}\x{FF01}\x{FF1F}\x{061F}\x{06D4}](?=\s)/u', $cut, $m, PREG_OFFSET_CAPTURE) > 0) {
            $last = $m[0][count($m[0]) - 1];
            $prefix = substr($cut, 0, $last[1] + strlen($last[0]));
            if (mb_strlen($prefix, 'UTF-8') >= (int) ($budget / 2)) {
                return $prefix;
            }
        }
        $space = strrpos($cut, ' ');
        if ($space !== false && $space > 0) {
            return rtrim(substr($cut, 0, $space));
        }

        return $cut;
    }

    /**
     * Words for the minimum-content test: whitespace-separated words, plus
     * characters of scripts written without spaces divided by two.
     */
    public static function wordCount(string $text): int
    {
        $noSpace = '\x{0E00}-\x{0EFF}\x{1000}-\x{109F}\x{1780}-\x{17FF}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}';
        $chars = preg_match_all('/[' . $noSpace . ']/u', $text);
        $rest = (string) preg_replace('/[' . $noSpace . ']+/u', ' ', $text);
        $words = preg_split('/\s+/u', trim($rest), -1, PREG_SPLIT_NO_EMPTY);
        $words = is_array($words) ? array_filter($words, static fn (string $w): bool => preg_match('/[\p{L}\p{N}]/u', $w) === 1) : [];

        return count($words) + intdiv((int) $chars, 2);
    }
}
