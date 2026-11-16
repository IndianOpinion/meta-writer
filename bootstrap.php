<?php

/**
 * Loads meta-writer without Composer: the bundled vendor/ copy when present,
 * else a small PSR-4 autoloader for src/ (the model library is then absent
 * and generation stays off until it is installed).
 */

declare(strict_types=1);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if (!class_exists(\IndianOpinion\MetaWriter\Plugin::class)) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'IndianOpinion\\MetaWriter\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

if (!function_exists('meta_writer_generate')) {
    require_once __DIR__ . '/src/functions.php';
}
