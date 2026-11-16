<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Text;

/**
 * Lengths as a reader counts them: grapheme clusters, never bytes.
 *
 * Uses the intl extension when present (grapheme_strlen and the ICU
 * character break iterator), else PCRE's \X (extended grapheme cluster),
 * which every PHP build has but which joins some emoji sequences too
 * eagerly in older PCRE2 releases. mb_strlen is the last resort, only
 * when the text is not valid UTF-8 for PCRE.
 */
final class Length
{
    private function __construct()
    {
    }

    public static function measure(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        if (function_exists('grapheme_strlen')) {
            $n = grapheme_strlen($text);
            if (is_int($n)) {
                return $n;
            }
        }
        $n = preg_match_all('/\X/u', $text);
        if (is_int($n)) {
            return $n;
        }

        return mb_strlen($text, 'UTF-8');
    }

    /**
     * Split into grapheme clusters.
     *
     * @return list<string>
     */
    public static function graphemes(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (class_exists(\IntlBreakIterator::class)) {
            $it = \IntlBreakIterator::createCharacterInstance('root');
            if ($it !== null) {
                $it->setText($text);
                $out = [];
                $prev = 0;
                foreach ($it as $pos) {
                    if ($pos > $prev) {
                        $out[] = substr($text, $prev, $pos - $prev);
                    }
                    $prev = $pos;
                }
                if ($out !== []) {
                    return $out;
                }
            }
        }
        if (preg_match_all('/\X/u', $text, $m) !== false) {
            return $m[0];
        }

        return mb_str_split($text, 1, 'UTF-8');
    }
}
