<?php

/**
 * Plugin Name: meta-writer settings in code (example)
 * Description: A must-use loader stub. meta-writer itself runs as a normal
 *              plugin (it has an activation step and an uninstall
 *              routine); this stub only pins its settings and the kill
 *              switch in code, so they cannot be changed from the screen.
 *              Copy into wp-content/mu-plugins/.
 */

declare(strict_types=1);

// Stop all generation, site-wide, whatever the settings screen says.
// define('META_WRITER_DISABLED', true);

// Keep the key out of the database.
if (!defined('META_WRITER_API_KEY') && getenv('META_WRITER_API_KEY') !== false) {
    define('META_WRITER_API_KEY', (string) getenv('META_WRITER_API_KEY'));
}

// Any setting can be fixed the same way: META_WRITER_ plus the key in capitals.
define('META_WRITER_DAILY_CAP', 100);
define('META_WRITER_PROVIDER', 'openrouter');
define('META_WRITER_MODEL', 'openai/gpt-4o-mini');

// Example of a filter: describe each post in its own language when a
// multilingual plugin stores it in post meta (adapt the key to yours).
add_filter('meta_writer_post_language', static function (string $language, WP_Post $post): string {
    $own = get_post_meta($post->ID, 'language', true);

    return is_string($own) && $own !== '' ? $own : $language;
}, 10, 2);
