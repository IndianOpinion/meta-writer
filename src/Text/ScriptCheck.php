<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Text;

/**
 * Is the output written in the script the language expects (case 68)?
 *
 * Scripts are matched by Unicode ranges. The expected script comes from a
 * BCP 47 tag: an explicit script subtag (hi-Latn, pa-Arab) wins, else the
 * primary language subtag is looked up in a small table that the
 * meta_writer_language_scripts filter can extend.
 */
final class ScriptCheck
{
    public const LATIN = 'Latin';
    public const DEVANAGARI = 'Devanagari';
    public const BENGALI = 'Bengali';
    public const TAMIL = 'Tamil';
    public const TELUGU = 'Telugu';
    public const KANNADA = 'Kannada';
    public const MALAYALAM = 'Malayalam';
    public const GUJARATI = 'Gujarati';
    public const GURMUKHI = 'Gurmukhi';
    public const ODIA = 'Odia';
    public const ARABIC = 'Arabic';
    public const CYRILLIC = 'Cyrillic';
    public const CJK = 'CJK';

    /** @var array<string, string> script => PCRE character class body */
    public const RANGES = [
        self::LATIN => 'A-Za-z\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{024F}\x{1E00}-\x{1EFF}\x{2C60}-\x{2C7F}\x{A720}-\x{A7FF}\x{AB30}-\x{AB6F}\x{FF21}-\x{FF3A}\x{FF41}-\x{FF5A}',
        self::DEVANAGARI => '\x{0900}-\x{097F}\x{A8E0}-\x{A8FF}',
        self::BENGALI => '\x{0980}-\x{09FF}',
        self::GURMUKHI => '\x{0A00}-\x{0A7F}',
        self::GUJARATI => '\x{0A80}-\x{0AFF}',
        self::ODIA => '\x{0B00}-\x{0B7F}',
        self::TAMIL => '\x{0B80}-\x{0BFF}',
        self::TELUGU => '\x{0C00}-\x{0C7F}',
        self::KANNADA => '\x{0C80}-\x{0CFF}',
        self::MALAYALAM => '\x{0D00}-\x{0D7F}',
        self::ARABIC => '\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}',
        self::CYRILLIC => '\x{0400}-\x{052F}\x{1C80}-\x{1C8F}\x{2DE0}-\x{2DFF}\x{A640}-\x{A69F}',
        self::CJK => '\x{1100}-\x{11FF}\x{3040}-\x{30FF}\x{3130}-\x{318F}\x{31F0}-\x{31FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}\x{FF66}-\x{FF9F}\x{20000}-\x{2FFFF}',
    ];

    /** ISO 15924 script subtags we understand. */
    private const SUBTAGS = [
        'latn' => self::LATIN, 'deva' => self::DEVANAGARI, 'beng' => self::BENGALI, 'taml' => self::TAMIL,
        'telu' => self::TELUGU, 'knda' => self::KANNADA, 'mlym' => self::MALAYALAM, 'gujr' => self::GUJARATI,
        'guru' => self::GURMUKHI, 'orya' => self::ODIA, 'arab' => self::ARABIC, 'cyrl' => self::CYRILLIC,
        'hani' => self::CJK, 'hans' => self::CJK, 'hant' => self::CJK, 'jpan' => self::CJK, 'kore' => self::CJK,
        'hira' => self::CJK, 'kana' => self::CJK, 'hang' => self::CJK,
    ];

    /** @var array<string, string> primary language subtag => script */
    public const LANGUAGES = [
        'hi' => self::DEVANAGARI, 'mr' => self::DEVANAGARI, 'ne' => self::DEVANAGARI, 'sa' => self::DEVANAGARI,
        'mai' => self::DEVANAGARI, 'kok' => self::DEVANAGARI, 'doi' => self::DEVANAGARI, 'bho' => self::DEVANAGARI,
        'bn' => self::BENGALI, 'as' => self::BENGALI, 'mni' => self::BENGALI,
        'ta' => self::TAMIL, 'te' => self::TELUGU, 'kn' => self::KANNADA, 'ml' => self::MALAYALAM,
        'gu' => self::GUJARATI, 'pa' => self::GURMUKHI, 'or' => self::ODIA,
        'ar' => self::ARABIC, 'ur' => self::ARABIC, 'fa' => self::ARABIC, 'ps' => self::ARABIC, 'sd' => self::ARABIC, 'ks' => self::ARABIC, 'ug' => self::ARABIC,
        'ru' => self::CYRILLIC, 'uk' => self::CYRILLIC, 'bg' => self::CYRILLIC, 'be' => self::CYRILLIC, 'mk' => self::CYRILLIC, 'sr' => self::CYRILLIC, 'kk' => self::CYRILLIC, 'ky' => self::CYRILLIC, 'mn' => self::CYRILLIC, 'tg' => self::CYRILLIC,
        'zh' => self::CJK, 'ja' => self::CJK, 'ko' => self::CJK,
        'en' => self::LATIN, 'fr' => self::LATIN, 'de' => self::LATIN, 'es' => self::LATIN, 'pt' => self::LATIN, 'it' => self::LATIN,
        'nl' => self::LATIN, 'sv' => self::LATIN, 'da' => self::LATIN, 'nb' => self::LATIN, 'nn' => self::LATIN, 'no' => self::LATIN,
        'fi' => self::LATIN, 'pl' => self::LATIN, 'cs' => self::LATIN, 'sk' => self::LATIN, 'sl' => self::LATIN, 'hr' => self::LATIN,
        'ro' => self::LATIN, 'hu' => self::LATIN, 'tr' => self::LATIN, 'id' => self::LATIN, 'ms' => self::LATIN, 'vi' => self::LATIN,
        'sw' => self::LATIN, 'ga' => self::LATIN, 'cy' => self::LATIN, 'et' => self::LATIN, 'lv' => self::LATIN, 'lt' => self::LATIN,
        'is' => self::LATIN, 'mt' => self::LATIN, 'sq' => self::LATIN, 'ca' => self::LATIN, 'eu' => self::LATIN, 'gl' => self::LATIN,
        'af' => self::LATIN, 'tl' => self::LATIN, 'fil' => self::LATIN, 'uz' => self::LATIN, 'az' => self::LATIN,
    ];

    private function __construct()
    {
    }

    /**
     * The script a BCP 47 tag expects, or null when unknown (no check).
     *
     * @param array<string, string>|null $table language => script, defaults to LANGUAGES
     */
    public static function expectedScript(string $bcp47, ?array $table = null): ?string
    {
        $parts = preg_split('/[-_]/', strtolower(trim($bcp47)));
        if (!is_array($parts) || $parts[0] === '') {
            return null;
        }
        foreach (array_slice($parts, 1) as $sub) {
            if (strlen($sub) === 4 && isset(self::SUBTAGS[$sub])) {
                return self::SUBTAGS[$sub];
            }
        }
        $table ??= self::LANGUAGES;
        $script = $table[$parts[0]] ?? null;

        return is_string($script) && isset(self::RANGES[$script]) ? $script : null;
    }

    /**
     * Share of letters in $text that belong to $script, from 0 to 1.
     * Text with no letters returns 1 (nothing to judge).
     */
    public static function share(string $text, string $script): float
    {
        $letters = preg_match_all('/\p{L}/u', $text);
        if (!is_int($letters) || $letters === 0) {
            return 1.0;
        }
        $range = self::RANGES[$script] ?? null;
        if ($range === null) {
            return 1.0;
        }
        $in = preg_match_all('/(?=\p{L})[' . $range . ']/u', $text);

        return (int) $in / $letters;
    }

    /** At least half the letters are in the expected script. */
    public static function passes(string $text, ?string $script, float $threshold = 0.5): bool
    {
        if ($script === null) {
            return true;
        }

        return self::share($text, $script) >= $threshold;
    }
}
