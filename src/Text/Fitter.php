<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Text;

use IndianOpinion\MetaWriter\Field;
use IndianOpinion\MetaWriter\FitResult;

/**
 * Normalises a value and shortens it to fit, without a model (PRD cases 23
 * to 27). Pure: no WordPress calls.
 */
final class Fitter
{
    public const ELLIPSIS = "\u{2026}";

    /** Sentence-ending marks, including the danda, CJK, Arabic and Urdu stops. */
    private const SENTENCE_END = '.!?\x{0964}\x{0965}\x{3002}\x{FF01}\x{FF1F}\x{FF0E}\x{061F}\x{06D4}';

    /** Wrapping quote pairs: straight, curly, guillemets, low-high, CJK corner brackets. */
    private const QUOTE_PAIRS = [
        ['"', '"'], ["'", "'"], ["\u{201C}", "\u{201D}"], ["\u{2018}", "\u{2019}"],
        ["\u{00AB}", "\u{00BB}"], ["\u{00BB}", "\u{00AB}"], ["\u{201E}", "\u{201C}"], ["\u{201A}", "\u{2018}"],
        ["\u{2039}", "\u{203A}"], ["\u{300C}", "\u{300D}"], ["\u{300E}", "\u{300F}"], ["`", "`"],
    ];

    private function __construct()
    {
    }

    /**
     * Strip tags, decode entities, flatten line breaks and control
     * characters, collapse spaces, remove a leading label and one pair of
     * wrapping quotes.
     */
    public static function normalise(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        $text = (string) preg_replace('/<[^>]*>/u', ' ', $text);
        $text = Cleaner::decode($text);
        $text = (string) preg_replace('/<[^>]*>/u', ' ', $text);
        $text = Cleaner::collapse($text);
        // Markdown emphasis wrappers such as **text** or __text__.
        $text = (string) preg_replace('/^(\*\*|__)(.+)\1$/u', '$2', $text);
        $text = (string) preg_replace('/^(?:seo\s+|meta\s+|page\s+)?(?:title|description|meta\s+description)\s*[:\x{FF1A}\-]\s*/iu', '', $text);
        $text = trim($text);
        foreach (self::QUOTE_PAIRS as [$open, $close]) {
            if (mb_strlen($text) >= 2 && str_starts_with($text, $open) && str_ends_with($text, $close)) {
                $inner = substr($text, strlen($open), -strlen($close));
                // Only a true wrapping pair: no quote of the same kind inside.
                if (!str_contains($inner, $open) && !str_contains($inner, $close)) {
                    $text = trim($inner);
                }
                break;
            }
        }

        return $text;
    }

    /**
     * Normalise, then shorten to $max when needed, then check $min.
     */
    public static function fit(string $text, int $min, int $max, string $field): FitResult
    {
        Field::assert($field);
        $text = self::normalise($text);
        $trimmed = false;
        if (Length::measure($text) > $max) {
            $text = self::shorten($text, $min, $max, $field);
            $trimmed = true;
        }
        $length = Length::measure($text);
        if ($text === '') {
            return new FitResult('', 0, false, $trimmed, FitResult::EMPTY);
        }
        if ($length < $min) {
            return new FitResult($text, $length, false, $trimmed, FitResult::TOO_SHORT);
        }

        return new FitResult($text, $length, true, $trimmed, null);
    }

    /**
     * Deterministic shortening (case 26). The result is never longer than
     * $max graphemes and never ends inside a word or grapheme cluster.
     */
    public static function shorten(string $text, int $min, int $max, string $field): string
    {
        $g = Length::graphemes($text);
        if (count($g) <= $max) {
            return $text;
        }
        $isDescription = $field === Field::DESCRIPTION;
        $window = array_slice($g, 0, $max + 1);
        $sentenceEnd = '/^[' . self::SENTENCE_END . ']$/u';

        // 1. Last sentence end within the limit that keeps at least the minimum.
        for ($i = $max - 1; $i >= max(0, $min - 1); $i--) {
            if (preg_match($sentenceEnd, $window[$i]) === 1 && (!isset($window[$i + 1]) || self::isSpace($window[$i + 1]) || self::isWide($window[$i]))) {
                return self::join(array_slice($g, 0, $i + 1));
            }
        }

        $limit = $isDescription ? $max - 1 : $max; // room for the ellipsis
        $hasSpaces = false;
        for ($i = 1; $i <= $limit && $i < count($window); $i++) {
            if (self::isSpace($window[$i])) {
                $hasSpaces = true;
                break;
            }
        }

        if ($hasSpaces) {
            // 2. Last word boundary within the limit.
            $cut = 0;
            for ($i = $limit; $i >= 1; $i--) {
                if (isset($g[$i]) && self::isSpace($g[$i])) {
                    $cut = $i;
                    break;
                }
            }
            $kept = array_slice($g, 0, $cut);
        } else {
            // 3. Scripts without spaces: last punctuation mark, else a grapheme boundary.
            $cut = $limit;
            for ($i = $limit - 1; $i >= 1; $i--) {
                if (preg_match('/^\p{P}$/u', $g[$i]) === 1) {
                    $cut = $i + 1;
                    break;
                }
            }
            $kept = array_slice($g, 0, $cut);
        }

        $out = self::stripTrailing(self::join($kept));
        if ($isDescription && $out !== '' && preg_match('/[' . self::SENTENCE_END . ']$/u', $out) !== 1) {
            $out .= self::ELLIPSIS;
        }

        return $out;
    }

    /** Remove trailing spaces and the punctuation that must not sit before an ellipsis or at a title's end. */
    private static function stripTrailing(string $text): string
    {
        return (string) preg_replace('/[\s,;:\x{3001}\x{FF0C}\x{FF1A}\x{FF1B}\x{060C}\x{061B}\-\x{2010}-\x{2015}\x{2212}(\[{]+$/u', '', $text);
    }

    /** @param list<string> $g */
    private static function join(array $g): string
    {
        return rtrim(implode('', $g));
    }

    private static function isSpace(string $g): bool
    {
        return preg_match('/^\s$/u', $g) === 1;
    }

    /** CJK full-width stops end a sentence without a following space. */
    private static function isWide(string $g): bool
    {
        return preg_match('/^[\x{3002}\x{FF01}\x{FF1F}\x{FF0E}]$/u', $g) === 1;
    }
}
