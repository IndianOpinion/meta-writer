<?php

/**
 * Removes what meta-writer created (PRD 6.5). Never touches any SEO
 * plugin's keys: values written there are the site's content now.
 * Fallback values are removed only when the operator ticked the box.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

\IndianOpinion\MetaWriter\Uninstaller::run();
