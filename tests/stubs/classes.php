<?php

declare(strict_types=1);

class WP_Post
{
    public int $ID = 0;
    public int $post_author = 1;
    public string $post_date = '2026-01-01 00:00:00';
    public string $post_date_gmt = '2026-01-01 00:00:00';
    public string $post_content = '';
    public string $post_title = '';
    public string $post_excerpt = '';
    public string $post_status = 'publish';
    public string $post_password = '';
    public string $post_name = '';
    public int $post_parent = 0;
    public string $post_type = 'post';
}

class WP_Error
{
    /** @var array<string, list<string>> */
    public array $errors = [];
    /** @var array<string, mixed> */
    public array $error_data = [];

    public function __construct(string $code = '', string $message = '', mixed $data = '')
    {
        if ($code !== '') {
            $this->errors[$code][] = $message;
            if ($data !== '') {
                $this->error_data[$code] = $data;
            }
        }
    }

    public function get_error_code(): string
    {
        return (string) (array_key_first($this->errors) ?? '');
    }

    public function get_error_message(): string
    {
        $code = $this->get_error_code();

        return $this->errors[$code][0] ?? '';
    }

    public function get_error_data(string $code = ''): mixed
    {
        return $this->error_data[$code === '' ? $this->get_error_code() : $code] ?? null;
    }
}

class WP_Site
{
    public string $blog_id = '1';
}

final class WpCliHalt extends RuntimeException
{
}

/** Records output; error() and halt() throw so tests can see the exit code. */
class WP_CLI
{
    /** @var list<string> */
    public static array $lines = [];
    /** @var list<string> */
    public static array $warnings = [];

    public static function reset(): void
    {
        self::$lines = [];
        self::$warnings = [];
    }

    public static function line(string $message = ''): void
    {
        self::$lines[] = $message;
    }

    public static function success(string $message): void
    {
        self::$lines[] = 'Success: ' . $message;
    }

    public static function warning(string $message): void
    {
        self::$warnings[] = $message;
    }

    public static function error(string $message): void
    {
        self::$lines[] = 'Error: ' . $message;
        throw new WpCliHalt($message, 1);
    }

    public static function halt(int $code): void
    {
        throw new WpCliHalt('halt', $code);
    }

    /** @param array<string, mixed> $assoc */
    public static function confirm(string $question, array $assoc = []): void
    {
        if (!isset($assoc['yes'])) {
            throw new WpCliHalt('not confirmed', 1);
        }
    }

    public static function add_command(string $name, mixed $callable): void
    {
    }
}
