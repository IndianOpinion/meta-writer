<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

use IndianOpinion\MetaWriter\Text\Cleaner;
use IndianOpinion\MetaWriter\Text\Fitter;
use IndianOpinion\MetaWriter\Text\Length;
use IndianOpinion\MetaWriter\Text\ScriptCheck;

/**
 * Validates one field against the limits and the content rules (cases 23
 * to 27, 68 and 69). Used for model output and for an editor's inline edit.
 */
final class Validator
{
    /** A description that opens with the title needs at least this much more. */
    public const MIN_EXTRA_AFTER_TITLE = 20;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function suffix(): string
    {
        $suffix = apply_filters('meta_writer_title_suffix', $this->settings->string('title_suffix'));

        return is_string($suffix) ? $suffix : '';
    }

    /**
     * The limits for this post, with the title budget reduced by the suffix.
     *
     * @return array{title_min: int, title_max: int, description_min: int, description_max: int}
     */
    public function limits(?\WP_Post $post): array
    {
        $limits = [
            'title_min' => $this->settings->int('title_min'),
            'title_max' => $this->settings->int('title_max'),
            'description_min' => $this->settings->int('description_min'),
            'description_max' => $this->settings->int('description_max'),
        ];
        if ($post !== null) {
            $filtered = apply_filters('meta_writer_limits', $limits, $post);
            if (is_array($filtered)) {
                foreach (array_keys($limits) as $key) {
                    if (isset($filtered[$key]) && is_numeric($filtered[$key]) && (int) $filtered[$key] > 0) {
                        $limits[$key] = (int) $filtered[$key];
                    }
                }
            }
        }
        $suffix = $this->suffix();
        if ($suffix !== '') {
            $limits['title_max'] = max(1, $limits['title_max'] - Length::measure($suffix));
            $limits['title_min'] = min($limits['title_min'], $limits['title_max']);
        }

        return $limits;
    }

    /**
     * @param string $compareTitle the generated title, for the description's same-as-title test
     */
    public function check(string $field, string $text, ?\WP_Post $post, string $language, string $compareTitle = ''): FitResult
    {
        $limits = $this->limits($post);
        $result = Fitter::fit($text, $limits[$field . '_min'], $limits[$field . '_max'], $field);

        if ($result->ok) {
            $table = apply_filters('meta_writer_language_scripts', ScriptCheck::LANGUAGES);
            $table = is_array($table) ? array_filter($table, 'is_string') : ScriptCheck::LANGUAGES;
            if (!ScriptCheck::passes($result->text, ScriptCheck::expectedScript($language, $table))) {
                $result = $result->fail(FitResult::WRONG_SCRIPT);
            }
        }

        if ($result->ok && $field === Field::DESCRIPTION) {
            $titles = array_filter([
                $post !== null ? Cleaner::collapse(Cleaner::decode((string) $post->post_title)) : '',
                Fitter::normalise($compareTitle),
            ], static fn (string $t): bool => $t !== '');
            if (self::repeatsTitle($result->text, $titles)) {
                $result = $result->fail(FitResult::SAME_AS_TITLE);
            }
        }

        if ($post !== null) {
            $filtered = apply_filters('meta_writer_validate', $result, $field, $post);
            // The filter may only turn ok into not ok.
            if ($filtered instanceof FitResult && $result->ok && !$filtered->ok) {
                $result = $result->fail($filtered->reason ?? FitResult::TOO_SHORT);
            }
        }

        return $result;
    }

    /** @param array<string> $titles */
    public static function repeatsTitle(string $description, array $titles): bool
    {
        $d = self::comparable($description);
        foreach ($titles as $title) {
            $t = self::comparable($title);
            if ($t === '') {
                continue;
            }
            if ($d === $t) {
                return true;
            }
            if (str_starts_with($d, $t) && Length::measure(trim(mb_substr($d, mb_strlen($t)))) < self::MIN_EXTRA_AFTER_TITLE) {
                return true;
            }
        }

        return false;
    }

    private static function comparable(string $text): string
    {
        $text = mb_strtolower(Fitter::normalise($text), 'UTF-8');
        $text = (string) preg_replace('/[\p{P}\p{S}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function describe(string $reason): string
    {
        return match ($reason) {
            FitResult::EMPTY => 'it was empty',
            FitResult::TOO_SHORT => 'it was too short',
            FitResult::SAME_AS_TITLE => 'the description repeated the title',
            FitResult::WRONG_SCRIPT => 'it was not written in the script of the requested language',
            default => 'it did not pass validation (' . $reason . ')',
        };
    }
}
