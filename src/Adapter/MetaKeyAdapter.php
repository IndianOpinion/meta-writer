<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Adapter;

use IndianOpinion\MetaWriter\Field;

/**
 * An adapter that keeps the two fields in two post meta keys.
 */
final class MetaKeyAdapter implements TargetAdapter
{
    /** @var \Closure(): bool */
    private \Closure $detector;

    /**
     * @param callable(): bool $detector
     * @param string           $templatePattern PCRE matching one template variable, or '' for none
     */
    public function __construct(
        private readonly string $id,
        private readonly string $label,
        private readonly string $titleKey,
        private readonly string $descriptionKey,
        callable $detector,
        private readonly bool $experimental = false,
        private readonly string $templatePattern = '',
        private readonly string $suffixHint = '',
    ) {
        $this->detector = \Closure::fromCallable($detector);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isAvailable(): bool
    {
        return (bool) ($this->detector)();
    }

    public function isExperimental(): bool
    {
        return $this->experimental;
    }

    public function key(string $field): string
    {
        return Field::assert($field) === Field::TITLE ? $this->titleKey : $this->descriptionKey;
    }

    public function read(int $postId, string $field): string
    {
        $value = get_post_meta($postId, $this->key($field), true);

        return is_scalar($value) ? (string) $value : '';
    }

    public function write(int $postId, string $field, string $value): void
    {
        // wp_slash: update_post_meta unslashes its input.
        update_post_meta($postId, $this->key($field), wp_slash($value));
    }

    public function clear(int $postId, string $field): void
    {
        delete_post_meta($postId, $this->key($field));
    }

    public function suffixHint(): string
    {
        return $this->suffixHint;
    }

    public function isTemplateOnly(string $value): bool
    {
        $value = trim($value);
        if ($this->templatePattern === '' || $value === '') {
            return false;
        }
        $rest = (string) preg_replace($this->templatePattern, '', $value);

        return $rest !== $value && trim($rest) === '';
    }
}
