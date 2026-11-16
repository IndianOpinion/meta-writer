<?php

/**
 * Plugin Name:       meta-writer
 * Plugin URI:        https://github.com/IndianOpinion/meta-writer
 * Description:       Drafts an SEO title and meta description from the post body, holds them for review, respects length limits and manual values, and writes only accepted text to the fields your SEO plugin reads.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       meta-writer
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (PHP_VERSION_ID < 80100) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>meta-writer needs PHP 8.1 or later.</p></div>';
    });

    return;
}

define('META_WRITER_FILE', __FILE__);

require_once __DIR__ . '/bootstrap.php';

\IndianOpinion\MetaWriter\Plugin::boot(__FILE__);
