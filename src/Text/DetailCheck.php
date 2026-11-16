<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Text;

/**
 * Finds URLs, email addresses and phone numbers in model output that are
 * not in the input (case 70). A hit is a flag for the reviewer, never an
 * automatic rejection.
 */
final class DetailCheck
{
    private function __construct()
    {
    }

    /** @return list<string> details in $output that $input does not contain */
    public static function unsupported(string $output, string $input): array
    {
        $found = [];
        $haystack = mb_strtolower($input, 'UTF-8');
        $digits = (string) preg_replace('/\D+/', '', $input);

        if (preg_match_all('#\b(?:https?://|www\.)[^\s<>"\x{201D}]+#iu', $output, $m) > 0) {
            foreach ($m[0] as $url) {
                $url = rtrim($url, '.,;:!?)');
                $bare = (string) preg_replace('#^(?:https?://)?(?:www\.)?#i', '', mb_strtolower($url, 'UTF-8'));
                if ($bare === '' || !str_contains($haystack, $bare)) {
                    $found[] = $url;
                }
            }
        }
        if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', $output, $m) > 0) {
            foreach ($m[0] as $email) {
                if (!str_contains($haystack, mb_strtolower($email, 'UTF-8'))) {
                    $found[] = $email;
                }
            }
        }
        if (preg_match_all('/(?<![\w])\+?\d[\d\s().\-]{6,}\d(?![\w])/u', $output, $m) > 0) {
            foreach ($m[0] as $phone) {
                $d = (string) preg_replace('/\D+/', '', $phone);
                // Years, ranges and plain counts are not phone numbers.
                if (strlen($d) < 8 || preg_match('/^\d{4}\s*[-\x{2013}]\s*\d{2,4}$/u', trim($phone)) === 1) {
                    continue;
                }
                if (!str_contains($digits, $d)) {
                    $found[] = trim($phone);
                }
            }
        }

        return array_values(array_unique($found));
    }
}
