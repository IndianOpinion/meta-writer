<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
define('META_WRITER_FILE', dirname(__DIR__) . '/meta-writer.php');

require __DIR__ . '/stubs/WpState.php';
require __DIR__ . '/stubs/classes.php';
require __DIR__ . '/stubs/functions.php';
require __DIR__ . '/stubs/wp-cli-utils.php';
require __DIR__ . '/stubs/wpdb.php';
require __DIR__ . '/stubs/companions.php';
require dirname(__DIR__) . '/vendor/autoload.php';
