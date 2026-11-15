<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

use IndianOpinion\MetaWriter\Exception\InvalidException;

/**
 * The two field names.
 */
final class Field
{
    public const TITLE = 'title';
    public const DESCRIPTION = 'description';
    public const ALL = [self::TITLE, self::DESCRIPTION];

    private function __construct()
    {
    }

    public static function assert(string $field): string
    {
        if (!in_array($field, self::ALL, true)) {
            throw new InvalidException(sprintf('Unknown field "%s"; use title or description.', $field), 'field');
        }

        return $field;
    }

    /**
     * Parse "title,description", a list, or null (both) into a clean list.
     *
     * @param string|list<string>|null $value
     * @return list<string>
     */
    public static function parseList(string|array|null $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return self::ALL;
        }
        $parts = is_array($value) ? $value : explode(',', $value);
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            self::assert($part);
            $out[$part] = true;
        }
        // Keep the canonical order.
        return array_values(array_filter(self::ALL, static fn (string $f): bool => isset($out[$f])));
    }
}
