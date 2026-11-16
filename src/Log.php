<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

/**
 * The log: fires meta_writer_log and keeps the last 500 lines in an
 * option with autoload off. Every message and context value is redacted.
 */
final class Log
{
    public const OPTION = 'meta_writer_log';
    public const KEEP = 500;

    /** @var list<string> secrets to scrub, such as the API key */
    private array $secrets = [];

    public function addSecret(string $secret): void
    {
        if (strlen($secret) >= 6) {
            $this->secrets[] = $secret;
        }
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function write(string $level, string $message, array $context = []): void
    {
        $message = $this->redact($message);
        $clean = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $clean[(string) $key] = is_string($value) ? $this->redact($value) : $value;
            } elseif (is_array($value)) {
                $json = wp_json_encode($value);
                $clean[(string) $key] = $this->redact(is_string($json) ? $json : '');
            }
        }
        do_action('meta_writer_log', $level, $message, $clean);

        $lines = get_option(self::OPTION, []);
        $lines = is_array($lines) ? array_values($lines) : [];
        $lines[] = ['time' => time(), 'level' => $level, 'message' => $message, 'context' => $clean];
        if (count($lines) > self::KEEP) {
            $lines = array_slice($lines, -self::KEEP);
        }
        update_option(self::OPTION, $lines, false);
    }

    /** @return list<array<string, mixed>> newest first */
    public function lines(int $limit = 100): array
    {
        $lines = get_option(self::OPTION, []);
        $lines = is_array($lines) ? array_values(array_filter($lines, 'is_array')) : [];

        return array_slice(array_reverse($lines), 0, max(1, $limit));
    }

    /** No key, no bearer token, at most 200 characters. */
    public function redact(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }
        $text = (string) preg_replace('/\b(?:sk|pk|rk)-[A-Za-z0-9_\-]{8,}/', '[redacted]', $text);
        $text = (string) preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $text);
        if (mb_strlen($text) > 200) {
            $text = mb_substr($text, 0, 200) . "\u{2026}";
        }

        return $text;
    }
}
