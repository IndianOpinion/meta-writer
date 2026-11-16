<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Db;

/**
 * Runs against a real MySQL or MariaDB. Configure with environment
 * variables META_WRITER_TEST_DB_HOST, _PORT, _USER, _PASS, _NAME or
 * _SOCKET. Skipped when none is set.
 */
abstract class DbTestCase extends \PHPUnit\Framework\TestCase
{
    protected \wpdb $db;

    public static function connect(): ?\mysqli
    {
        $socket = getenv('META_WRITER_TEST_DB_SOCKET') ?: null;
        $host = getenv('META_WRITER_TEST_DB_HOST') ?: null;
        if ($socket === null && $host === null) {
            return null;
        }
        $name = getenv('META_WRITER_TEST_DB_NAME') ?: 'meta_writer_test';
        $m = new \mysqli($host ?? 'localhost', getenv('META_WRITER_TEST_DB_USER') ?: 'root', getenv('META_WRITER_TEST_DB_PASS') ?: '', '', (int) (getenv('META_WRITER_TEST_DB_PORT') ?: 3306), $socket);
        $m->set_charset('utf8mb4');
        $m->query('CREATE DATABASE IF NOT EXISTS `' . $m->real_escape_string($name) . '`');
        $m->select_db($name);

        return $m;
    }

    protected function setUp(): void
    {
        $m = self::connect();
        if ($m === null) {
            $this->markTestSkipped('No test database configured (META_WRITER_TEST_DB_SOCKET or META_WRITER_TEST_DB_HOST).');
        }
        \WpState::reset();
        $this->db = new \wpdb($m, 'wp_');
        $GLOBALS['wpdb'] = $this->db;
        foreach (['wp_options', 'wp_posts', 'wp_meta_writer_suggestions'] as $t) {
            $m->query('DROP TABLE IF EXISTS ' . $t);
        }
        $m->query("CREATE TABLE wp_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL DEFAULT '', option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) DEFAULT CHARSET=utf8mb4");
        $m->query("CREATE TABLE wp_posts (ID bigint unsigned NOT NULL AUTO_INCREMENT, post_date datetime NOT NULL DEFAULT '2026-01-01 00:00:00', post_title text NOT NULL, post_status varchar(20) NOT NULL DEFAULT 'publish', post_password varchar(255) NOT NULL DEFAULT '', post_type varchar(20) NOT NULL DEFAULT 'post', PRIMARY KEY (ID), KEY type_status_date (post_type, post_status, post_date, ID)) DEFAULT CHARSET=utf8mb4");
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }
}
