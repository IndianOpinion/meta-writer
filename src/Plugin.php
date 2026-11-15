<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

use IndianOpinion\MetaWriter\Admin\MetaBox;
use IndianOpinion\MetaWriter\Admin\Notices;
use IndianOpinion\MetaWriter\Admin\ReviewPage;
use IndianOpinion\MetaWriter\Admin\SettingsPage;
use IndianOpinion\MetaWriter\Caller\CallerFactory;
use IndianOpinion\MetaWriter\Cli\Command;
use IndianOpinion\MetaWriter\Frontend\FallbackOutput;
use IndianOpinion\MetaWriter\Integration\GenQueue;
use IndianOpinion\MetaWriter\Rest\RestController;
use IndianOpinion\MetaWriter\Store\WpdbSuggestionStore;

/**
 * Wires everything into WordPress.
 */
final class Plugin
{
    public const VERSION = '0.1.0';
    public const DB_VERSION = '1';
    public const DB_VERSION_OPTION = 'meta_writer_db_version';
    public const SCHEMA_OPTION = 'meta_writer_missing_columns';

    public static function boot(string $file): void
    {
        register_activation_hook($file, [self::class, 'activate']);
        register_deactivation_hook($file, [self::class, 'deactivate']);
        add_action('plugins_loaded', [self::class, 'init']);
        add_action('wp_initialize_site', [self::class, 'onNewSite'], 20);
    }

    public static function init(): void
    {
        $c = Container::get();
        self::maybeUpgrade($c);

        (new Cron($c))->register();
        (new FallbackOutput($c))->register();
        (new RestController($c))->register();

        add_action('init', [Prompt::class, 'register']);
        add_action('deleted_post', static function (int $postId) use ($c): void {
            $c->suggestions->deleteForPost($postId);
        });
        add_action(GenQueue::HANDLER_ACTION, [self::class, 'handleQueuedJob']);
        add_action('admin_init', [self::class, 'privacyText']);
        add_action('switch_blog', static function (): void {
            Container::set(null);
        });

        if (is_admin()) {
            (new SettingsPage($c))->register();
            (new ReviewPage($c))->register();
            (new MetaBox($c))->register();
            (new Notices($c))->register();
        }
        if (defined('WP_CLI') && (bool) constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('meta-writer', new Command($c));
        }
    }

    public static function activate(bool $networkWide = false): void
    {
        if (is_multisite() && $networkWide) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::installSite();
                restore_current_blog();
            }

            return;
        }
        self::installSite();
    }

    public static function onNewSite(\WP_Site $site): void
    {
        if (!is_plugin_active_for_network(plugin_basename(META_WRITER_FILE))) {
            return;
        }
        switch_to_blog((int) $site->blog_id);
        self::installSite();
        restore_current_blog();
    }

    public static function installSite(): void
    {
        Container::set(null);
        $c = Container::get();
        $c->suggestions->install();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
        self::verifySchema($c);
        if (get_option(KillSwitch::OPTION, null) === null) {
            add_option(KillSwitch::OPTION, '0', '', true);
        }
        Cron::schedule();
        Container::set(null);
    }

    public static function deactivate(): void
    {
        Cron::unschedule();
    }

    private static function maybeUpgrade(Container $c): void
    {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            $c->suggestions->install();
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
            self::verifySchema($c);
            Cron::schedule();
        }
    }

    /** dbDelta has silently skipped columns before: check with SHOW COLUMNS. */
    public static function verifySchema(Container $c): void
    {
        $missing = $c->suggestions->missingColumns();
        if ($missing === []) {
            delete_option(self::SCHEMA_OPTION);
        } else {
            update_option(self::SCHEMA_OPTION, $missing, false);
            $c->log->error('Suggestions table is missing columns: ' . implode(', ', $missing), ['event' => 'schema']);
        }
    }

    /** @param mixed $payload */
    public static function handleQueuedJob($payload): void
    {
        $c = Container::get();
        try {
            $payload = is_array($payload) ? $payload : [];
            $postId = (int) ($payload['post_id'] ?? 0);
            if ($postId <= 0) {
                return;
            }
            // Case 52: the generator re-checks the kill switch and the cap.
            $c->generator->generate($postId, [
                'fields' => Field::parseList(isset($payload['fields']) && is_array($payload['fields']) ? array_values(array_map('strval', $payload['fields'])) : null),
                'regenerate' => !empty($payload['regenerate']),
                'refresh_own' => !empty($payload['refresh_own']),
                'user_id' => (int) ($payload['user_id'] ?? 0),
                'dry_run' => false,
                'from_queue' => true,
            ]);
        } catch (\Throwable $e) {
            $c->log->error('Queued job failed.', ['event' => 'queue_error', 'error' => (new \ReflectionClass($e))->getShortName()]);
        }
    }

    public static function privacyText(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(
            'meta-writer',
            wp_kses_post('<p>' . __('When an editor asks for a suggested SEO title and meta description, the title, excerpt and text of that post are sent to the language model provider the site operator has configured. No author details, comments or custom fields are sent. Private, draft and password-protected posts are excluded by default.', 'meta-writer') . '</p>'),
        );
    }

    public static function libraryMissing(): bool
    {
        return !CallerFactory::libraryAvailable() && !has_filter('meta_writer_caller');
    }

    public static function suggestionsTable(): string
    {
        global $wpdb;

        return $wpdb instanceof \wpdb ? (new WpdbSuggestionStore($wpdb))->table() : '';
    }
}
