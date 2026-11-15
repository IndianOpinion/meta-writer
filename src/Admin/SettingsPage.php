<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Admin;

use IndianOpinion\MetaWriter\Container;
use IndianOpinion\MetaWriter\Cron;
use IndianOpinion\MetaWriter\Exception\TargetUnavailableException;
use IndianOpinion\MetaWriter\Field;
use IndianOpinion\MetaWriter\RunReport;
use IndianOpinion\MetaWriter\Runner;
use IndianOpinion\MetaWriter\Settings;
use IndianOpinion\MetaWriter\Status;

/**
 * Settings, status panel, kill switch, bulk run and log
 * (Settings > meta-writer). Requires the settings capability.
 */
final class SettingsPage
{
    public const SLUG = 'meta-writer';

    public function __construct(private readonly Container $c)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_meta_writer_save_settings', [$this, 'save']);
        add_action('admin_post_meta_writer_kill', [$this, 'toggleKill']);
        add_action('admin_post_meta_writer_bulk', [$this, 'bulk']);
    }

    public function menu(): void
    {
        add_options_page('meta-writer', 'meta-writer', $this->c->caps->capability('settings'), self::SLUG, [$this, 'render']);
    }

    private function guard(string $action): void
    {
        if (!current_user_can($this->c->caps->capability('settings'))) {
            wp_die(esc_html__('You may not change meta-writer settings.', 'meta-writer'), 403);
        }
        check_admin_referer($action);
    }

    /** @param array<string, string> $args */
    private function back(array $args = []): never
    {
        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php?page=' . self::SLUG)));
        exit;
    }

    public function save(): void
    {
        $this->guard('meta_writer_save_settings');
        $input = isset($_POST['meta_writer']) && is_array($_POST['meta_writer']) ? wp_unslash($_POST['meta_writer']) : [];
        $input = is_array($input) ? $input : [];
        foreach (['on_publish', 'use_queue', 'allow_authors', 'keep_raw', 'template_as_empty', 'remove_fallback_on_uninstall'] as $box) {
            $input[$box] = isset($input[$box]) ? '1' : '0';
        }
        $input['fields'] = isset($input['fields']) && is_array($input['fields']) ? $input['fields'] : [];
        if ($input['fields'] === []) {
            unset($input['fields']);
        }
        $public = array_values(get_post_types(['public' => true]));
        [$values, $errors] = $this->c->settings->validate($input, array_map('strval', $public));
        update_option(Settings::OPTION, $values, true);
        $this->c->settings->flush();
        set_transient('meta_writer_settings_errors_' . get_current_user_id(), $errors, 300);
        $this->back(['saved' => $errors === [] ? '1' : '0']);
    }

    public function toggleKill(): void
    {
        $this->guard('meta_writer_kill');
        $on = isset($_POST['on']) && $_POST['on'] === '1';
        $this->c->kill->set($on, 'user:' . get_current_user_id());
        $this->c->log->info($on ? 'Kill switch turned on.' : 'Kill switch turned off.', ['event' => 'kill', 'user' => get_current_user_id()]);
        $this->back();
    }

    public function bulk(): void
    {
        $this->guard('meta_writer_bulk');
        $step = isset($_POST['step']) ? sanitize_key((string) wp_unslash($_POST['step'])) : 'dry';
        $cron = new Cron($this->c);
        if ($step === 'cancel') {
            $cron->cancelBulk();
            $this->back(['bulk' => 'cancelled']);
        }
        $fields = isset($_POST['fields']) && is_array($_POST['fields']) ? array_map('sanitize_key', wp_unslash($_POST['fields'])) : [];
        $args = [
            'fields' => Field::parseList(array_values(array_map('strval', $fields))),
            'limit' => isset($_POST['limit']) ? absint(wp_unslash($_POST['limit'])) : 0,
            'regenerate' => !empty($_POST['regenerate']),
            'order' => isset($_POST['order']) && $_POST['order'] === 'newest' ? 'newest' : 'oldest',
        ];
        if ($step === 'run') {
            if (empty($_POST['confirm'])) {
                $this->back(['bulk' => 'unconfirmed']);
            }
            $cron->startBulk($args, get_current_user_id());
            $this->back(['bulk' => 'started']);
        }
        // Step one: always a dry run (case 44). Bounded so the request stays short.
        $report = $this->c->runner->run($args + ['dry_run' => true, 'time' => 20.0]);
        set_transient('meta_writer_dry_' . get_current_user_id(), ['args' => $args, 'report' => $report->toArray()], 900);
        $this->back(['bulk' => 'dry']);
    }

    public function render(): void
    {
        if (!current_user_can($this->c->caps->capability('settings'))) {
            return;
        }
        $s = $this->c->settings;
        $errors = get_transient('meta_writer_settings_errors_' . get_current_user_id());
        delete_transient('meta_writer_settings_errors_' . get_current_user_id());
        echo '<div class="wrap"><h1>meta-writer</h1>';
        if (is_array($errors) && $errors !== []) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Some values were not saved:', 'meta-writer') . '</p><ul>';
            foreach ($errors as $msg) {
                echo '<li>' . esc_html((string) $msg) . '</li>';
            }
            echo '</ul></div>';
        }
        echo '<p>' . esc_html__('Post title, excerpt and text are sent to the model provider you configure below. Private, draft and password-protected posts are excluded by default.', 'meta-writer') . '</p>';

        $this->renderStatus();
        $this->renderKill();
        $this->renderBulk();

        echo '<h2>' . esc_html__('Settings', 'meta-writer') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('meta_writer_save_settings');
        echo '<input type="hidden" name="action" value="meta_writer_save_settings" /><table class="form-table" role="presentation">';
        $targets = ['auto' => 'auto'];
        foreach ($this->c->adapters->all() as $id => $adapter) {
            $targets[$id] = $adapter->label();
        }
        $this->select('target', 'Target', $targets);
        echo '<tr><th>' . esc_html__('Fields', 'meta-writer') . '</th><td>';
        foreach (Field::ALL as $field) {
            printf('<label><input type="checkbox" name="meta_writer[fields][]" value="%1$s" %2$s %3$s /> %1$s</label> ', esc_attr($field), checked(in_array($field, $s->list('fields'), true), true, false), disabled($s->fixedByConstant('fields'), true, false));
        }
        echo '</td></tr>';
        $this->text('post_types', 'Post types (comma separated, public only)');
        $this->text('post_statuses', 'Post statuses');
        foreach (['title_min' => 'Title minimum', 'title_max' => 'Title maximum', 'description_min' => 'Description minimum', 'description_max' => 'Description maximum', 'body_budget' => 'Body budget (characters)', 'min_words' => 'Minimum words', 'daily_cap' => 'Daily cap (model calls)', 'on_publish_delay' => 'On-publish delay (seconds)', 'retention_days' => 'Retention (days)', 'cron_time_budget' => 'Cron time budget (seconds)', 'batch_size' => 'Batch size'] as $key => $label) {
            $this->text($key, $label, 'number');
        }
        $this->text('title_suffix', 'Title suffix the SEO plugin appends');
        foreach (['on_publish' => 'Suggest on first publish', 'use_queue' => 'Use the generation queue when present', 'allow_authors' => 'Authors may generate and accept for their own posts', 'keep_raw' => 'Keep raw model output for failed suggestions (14 days)', 'template_as_empty' => 'Treat a field holding only the SEO plugin\'s template variables as empty', 'remove_fallback_on_uninstall' => 'Remove fallback values on uninstall'] as $key => $label) {
            printf('<tr><th>%s</th><td><input type="checkbox" name="meta_writer[%s]" value="1" %s %s />%s</td></tr>', esc_html($label), esc_attr($key), checked($s->bool($key), true, false), disabled($s->fixedByConstant($key), true, false), $this->fixedNote($key));
        }
        $this->select('provider', 'Provider', ['openrouter' => 'OpenRouter', 'openai' => 'OpenAI', 'generic' => 'Other chat completions endpoint']);
        $this->text('model', 'Model');
        $this->text('fallback_model', 'Fallback model (optional)');
        $this->text('base_url', 'Base URL (other endpoint only)');
        $key = $s->apiKey();
        echo '<tr><th>' . esc_html__('API key', 'meta-writer') . '</th><td>';
        if ($s->fixedByConstant('api_key')) {
            echo esc_html(sprintf(__('Set by META_WRITER_API_KEY (%s).', 'meta-writer'), Settings::maskKey($key)));
        } else {
            printf('<input type="password" name="meta_writer[api_key]" value="" autocomplete="off" placeholder="%s" /> <label><input type="checkbox" name="meta_writer[api_key_clear]" value="1" /> %s</label>', esc_attr($key === '' ? __('not set', 'meta-writer') : Settings::maskKey($key)), esc_html__('remove', 'meta-writer'));
        }
        echo '</td></tr></table>';
        submit_button();
        echo '</form>';

        $this->renderLog();
        echo '</div>';
    }

    private function fixedNote(string $key): string
    {
        return $this->c->settings->fixedByConstant($key) ? ' <em>' . esc_html(sprintf(__('fixed by %s', 'meta-writer'), Settings::constantName($key))) . '</em>' : '';
    }

    private function text(string $key, string $label, string $type = 'text'): void
    {
        printf(
            '<tr><th><label for="mw-%1$s">%2$s</label></th><td><input id="mw-%1$s" type="%3$s" name="meta_writer[%1$s]" value="%4$s" class="regular-text" %5$s />%6$s</td></tr>',
            esc_attr($key),
            esc_html($label),
            esc_attr($type),
            esc_attr($this->c->settings->string($key)),
            disabled($this->c->settings->fixedByConstant($key), true, false),
            $this->fixedNote($key),
        );
    }

    /** @param array<string, string> $options */
    private function select(string $key, string $label, array $options): void
    {
        printf('<tr><th><label for="mw-%1$s">%2$s</label></th><td><select id="mw-%1$s" name="meta_writer[%1$s]" %3$s>', esc_attr($key), esc_html($label), disabled($this->c->settings->fixedByConstant($key), true, false));
        foreach ($options as $value => $text) {
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($this->c->settings->string($key), $value, false), esc_html($text));
        }
        echo '</select>' . $this->fixedNote($key) . '</td></tr>';
    }

    private function renderStatus(): void
    {
        $c = $this->c;
        try {
            $target = $c->adapters->resolve($c->settings->string('target'))->label();
        } catch (TargetUnavailableException $e) {
            $target = 'unavailable: ' . $e->getMessage();
        }
        $counts = $c->suggestions->countByStatus();
        $last = get_option(Runner::LAST_RUN_OPTION);
        $rows = [
            'Target' => $target,
            'SEO plugins detected' => implode(', ', array_map(static fn ($a): string => $a->label(), $c->adapters->detected())) ?: 'none',
            'Kill switch' => $c->kill->isOn() ? 'on (' . $c->kill->source() . ')' : 'off',
            'Model calls today' => sprintf('%d used, %d left of %d', $c->cap->used(), $c->cap->remaining(), $c->cap->cap()),
            'Pending / stale / failed' => sprintf('%d / %d / %d', $counts[Status::PENDING] ?? 0, $counts[Status::STALE] ?? 0, $counts[Status::FAILED] ?? 0),
            'Last run' => is_array($last) ? (string) wp_json_encode(RunReport::fromArray($last)->toArray()) : 'none',
        ];
        echo '<h2>' . esc_html__('Status', 'meta-writer') . '</h2><table class="widefat striped" style="max-width:900px">';
        foreach ($rows as $k => $v) {
            printf('<tr><th style="width:220px">%s</th><td>%s</td></tr>', esc_html($k), esc_html($v));
        }
        echo '</table>';
    }

    private function renderKill(): void
    {
        $on = $this->c->kill->isOn();
        echo '<h2>' . esc_html__('Kill switch', 'meta-writer') . '</h2>';
        if ($this->c->kill->constantOn()) {
            echo '<p>' . esc_html__('META_WRITER_DISABLED is set in code; this screen cannot turn generation back on.', 'meta-writer') . '</p>';

            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('meta_writer_kill');
        printf('<input type="hidden" name="action" value="meta_writer_kill" /><input type="hidden" name="on" value="%s" />', $this->c->kill->optionOn() ? '0' : '1');
        submit_button($this->c->kill->optionOn() ? __('Turn generation back on', 'meta-writer') : __('Stop all generation now', 'meta-writer'), $on ? 'secondary' : 'delete', 'submit', false);
        echo '</form>';
    }

    private function renderBulk(): void
    {
        echo '<h2>' . esc_html__('Bulk run', 'meta-writer') . '</h2>';
        $job = get_option(Cron::BULK_OPTION);
        $disabled = $this->c->kill->isOn() || !$this->c->canGenerate();
        $form = static function (string $step, string $label, string $extra = '') use ($disabled): void {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:1em">';
            wp_nonce_field('meta_writer_bulk');
            printf('<input type="hidden" name="action" value="meta_writer_bulk" /><input type="hidden" name="step" value="%s" />', esc_attr($step));
            echo $extra; // Built from escaped parts below.
            submit_button($label, $step === 'run' ? 'primary' : 'secondary', 'submit', false, $disabled && $step !== 'cancel' ? ['disabled' => 'disabled'] : []);
            echo '</form>';
        };
        if (is_array($job)) {
            echo '<p>' . esc_html(sprintf(__('A bulk run is in progress (%d ticks so far).', 'meta-writer'), (int) ($job['ticks'] ?? 0))) . '</p>';
            $form('cancel', __('Cancel the bulk run', 'meta-writer'));

            return;
        }
        $dry = get_transient('meta_writer_dry_' . get_current_user_id());
        if (is_array($dry) && isset($_GET['bulk']) && $_GET['bulk'] === 'dry' && is_array($dry['report'] ?? null) && is_array($dry['args'] ?? null)) {
            $r = RunReport::fromArray($dry['report']);
            echo '<p>' . esc_html(sprintf(__('Dry run: %1$d posts considered, %2$d model calls a real run would make, %3$d fit in today\'s remaining cap. Skipped: %4$s. Stop: %5$s.', 'meta-writer'), $r->considered, $r->calls, $r->fitsToday, (string) wp_json_encode($r->skipped), $r->stopReason)) . '</p>';
            $a = $dry['args'];
            $hidden = '';
            foreach ((array) ($a['fields'] ?? []) as $f) {
                $hidden .= '<input type="hidden" name="fields[]" value="' . esc_attr((string) $f) . '" />';
            }
            $hidden .= '<input type="hidden" name="limit" value="' . esc_attr((string) (int) ($a['limit'] ?? 0)) . '" />';
            $hidden .= '<input type="hidden" name="order" value="' . esc_attr((string) ($a['order'] ?? 'oldest')) . '" />';
            $hidden .= !empty($a['regenerate']) ? '<input type="hidden" name="regenerate" value="1" />' : '';
            $hidden .= '<label><input type="checkbox" name="confirm" value="1" /> ' . esc_html__('I have read the dry run; spend model calls now.', 'meta-writer') . '</label> ';
            $form('run', __('Run for real', 'meta-writer'), $hidden);
        }
        $fields = '';
        foreach (Field::ALL as $f) {
            $fields .= '<label><input type="checkbox" name="fields[]" value="' . esc_attr($f) . '" checked /> ' . esc_html($f) . '</label> ';
        }
        $fields .= '<label>' . esc_html__('Limit', 'meta-writer') . ' <input type="number" name="limit" value="0" min="0" style="width:6em" /></label> ';
        $fields .= '<select name="order"><option value="oldest">oldest first</option><option value="newest">newest first</option></select> ';
        $form('dry', __('Dry run', 'meta-writer'), $fields);
    }

    private function renderLog(): void
    {
        echo '<h2>' . esc_html__('Log', 'meta-writer') . '</h2><table class="widefat striped"><thead><tr><th>Time</th><th>Level</th><th>Message</th><th>Context</th></tr></thead><tbody>';
        foreach ($this->c->log->lines(100) as $line) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
                esc_html(wp_date('Y-m-d H:i:s', (int) ($line['time'] ?? 0)) ?: ''),
                esc_html((string) ($line['level'] ?? '')),
                esc_html((string) ($line['message'] ?? '')),
                esc_html((string) wp_json_encode($line['context'] ?? [])),
            );
        }
        echo '</tbody></table>';
    }
}
