<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Admin;

use IndianOpinion\MetaWriter\Container;
use IndianOpinion\MetaWriter\Exception\TargetUnavailableException;
use IndianOpinion\MetaWriter\Plugin;

/**
 * Admin notices (PRD section 11), shown to users who can manage settings.
 */
final class Notices
{
    public function __construct(private readonly Container $c)
    {
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    /** @return list<array{0: string, 1: string}> level and text */
    public function collect(): array
    {
        $out = [];
        if ($this->c->kill->isOn()) {
            $source = $this->c->kill->source();
            $out[] = ['warning', $source === 'constant'
                ? 'meta-writer: generation is off because META_WRITER_DISABLED is set in code. The settings screen cannot turn it back on.'
                : ($source === 'network' ? 'meta-writer: generation is off for the whole network.' : 'meta-writer: the kill switch is on. No model calls are made; review still works.')];
        }
        if ($this->c->cap->remaining() <= 0) {
            $out[] = ['warning', sprintf('meta-writer: today\'s cap of %d calls is used up. It resets at midnight, site time.', $this->c->cap->cap())];
        }
        try {
            $adapter = $this->c->adapters->resolve($this->c->settings->string('target'));
            if ($adapter->isExperimental()) {
                $out[] = ['info', sprintf('meta-writer: the %s target is experimental. It writes post meta only and the value may not appear on the page.', $adapter->label())];
            }
        } catch (TargetUnavailableException $e) {
            $out[] = ['error', 'meta-writer: ' . $e->getMessage() . ' Generation and accept are blocked until a target is chosen.'];
        }
        if (Plugin::libraryMissing()) {
            $out[] = ['error', 'meta-writer: the model library (indianopinion/llm-json-php) is missing. Generation is off; review, accept and revert still work.'];
        } elseif (!$this->c->canGenerate()) {
            $out[] = ['warning', 'meta-writer: no model is configured (provider, model and key), so generation is off. Review, accept and revert still work.'];
        }
        $missing = get_option(Plugin::SCHEMA_OPTION);
        if (is_array($missing) && $missing !== []) {
            $out[] = ['error', 'meta-writer: the suggestions table is missing columns: ' . implode(', ', array_map('strval', $missing)) . '. Deactivate and activate the plugin, then check the database user can ALTER tables.'];
        }

        return $out;
    }

    public function render(): void
    {
        if (!current_user_can($this->c->caps->capability('settings'))) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen !== null && !str_contains((string) $screen->id, 'meta-writer') && !in_array($screen->base, ['dashboard', 'plugins'], true)) {
            return;
        }
        foreach ($this->collect() as [$level, $text]) {
            printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($level), esc_html($text));
        }
    }
}
