<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

use IndianOpinion\MetaWriter\Store\WpdbCounterStore;
use IndianOpinion\MetaWriter\Store\WpdbSuggestionStore;

/**
 * Uninstall: per site, and for every site on multisite.
 */
final class Uninstaller
{
    public const OPTIONS = [
        Settings::OPTION, KillSwitch::OPTION, Log::OPTION, Runner::LAST_RUN_OPTION,
        Plugin::DB_VERSION_OPTION, Plugin::SCHEMA_OPTION, Cron::BULK_OPTION,
    ];

    public static function run(): void
    {
        if (is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::site();
                restore_current_blog();
            }
            delete_site_option(KillSwitch::NETWORK_OPTION);

            return;
        }
        self::site();
    }

    public static function site(): void
    {
        global $wpdb;
        $settings = get_option(Settings::OPTION, []);
        $removeFallback = is_array($settings) && !empty($settings['remove_fallback_on_uninstall']);

        Cron::unschedule();
        if ($wpdb instanceof \wpdb) {
            (new WpdbSuggestionStore($wpdb))->drop();
            $wpdb->query((string) $wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(WpdbCounterStore::PREFIX) . '%'));
        }
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }

        $keys = [Provenance::META_KEY, '_meta_writer_skip'];
        if ($removeFallback) {
            $keys[] = '_meta_writer_title';
            $keys[] = '_meta_writer_description';
        }
        foreach ($keys as $key) {
            delete_post_meta_by_key($key);
        }
    }
}
