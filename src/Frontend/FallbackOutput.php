<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Frontend;

use IndianOpinion\MetaWriter\Adapter\AdapterRegistry;
use IndianOpinion\MetaWriter\Container;
use IndianOpinion\MetaWriter\Field;

/**
 * Head output for the fallback target (case 63). Only on singular views,
 * only when no SEO plugin is active, and it fails open: any error prints
 * nothing extra.
 */
final class FallbackOutput
{
    public function __construct(private readonly Container $c)
    {
    }

    public function register(): void
    {
        add_action('wp_head', [$this, 'printDescription'], 1);
        add_filter('pre_get_document_title', [$this, 'filterTitle'], 20);
    }

    public function active(): bool
    {
        return $this->c->adapters->detected() === [];
    }

    private function value(string $field): string
    {
        if (!is_singular() || !$this->active()) {
            return '';
        }
        $id = get_queried_object_id();
        if ($id <= 0) {
            return '';
        }
        $adapter = $this->c->adapters->get(AdapterRegistry::FALLBACK);

        return $adapter === null ? '' : trim($adapter->read($id, $field));
    }

    public function printDescription(): void
    {
        try {
            $value = $this->value(Field::DESCRIPTION);
            if ($value !== '') {
                echo '<meta name="description" content="' . esc_attr($value) . '" />' . "\n";
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    public function filterTitle(mixed $title): mixed
    {
        try {
            $value = $this->value(Field::TITLE);

            return $value !== '' ? esc_html($value) : $title;
        } catch (\Throwable $e) {
            return $title;
        }
    }
}
