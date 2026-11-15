<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

/**
 * The prompt (cases 16, 17, 19, 58). Generic: it says what to write and
 * the JSON shape, nothing about any site's style.
 */
final class Prompt
{
    public const CONSUMER = 'meta-writer/title-description';
    public const BUILTIN_VERSION = 'builtin-1';
    public const VARIABLES = ['title', 'excerpt', 'body', 'language', 'title_min', 'title_max', 'description_min', 'description_max'];

    public function __construct(private readonly Log $log)
    {
    }

    /** @return array<string, mixed> the strict output schema */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
            ],
            'required' => ['title', 'description'],
            'additionalProperties' => false,
        ];
    }

    public static function systemText(): string
    {
        return 'You write the SEO title and the meta description for one web page. '
            . 'Describe this page accurately, using only facts that are in the page text given to you. '
            . 'Write in the language named by the language tag. '
            . 'The title must be between {{title_min}} and {{title_max}} characters. '
            . 'The description must be between {{description_min}} and {{description_max}} characters, in one or two complete sentences. '
            . 'Do not put quotation marks around either value. No markdown, no emoji, no site name, no clickbait, '
            . 'no claim that is not in the page text, and do not repeat the title as the description. '
            . 'Answer with a JSON object with exactly two string properties: "title" and "description".';
    }

    public static function userText(): string
    {
        return "Language: {{language}}\n\nPage title: {{title}}\n\nExcerpt: {{excerpt}}\n\nPage text:\n{{body}}";
    }

    /** Tell prompt-library about our default prompt, when it listens. */
    public static function register(): void
    {
        do_action('prompt_library_register', self::CONSUMER, [
            'label' => 'meta-writer: SEO title and meta description',
            'version' => self::BUILTIN_VERSION,
            'variables' => self::VARIABLES,
            'messages' => [
                ['role' => 'system', 'content' => self::systemText()],
                ['role' => 'user', 'content' => self::userText()],
            ],
            'schema' => self::schema(),
        ]);
    }

    /**
     * @param array<string, string|int> $vars
     * @return array{0: list<array{role: string, content: string}>, 1: string} messages and prompt version
     */
    public function build(array $vars, ?\WP_Post $post, string $retryNote = ''): array
    {
        [$messages, $version] = $this->fromLibrary($vars) ?? [self::builtin($vars), self::BUILTIN_VERSION];
        if ($post !== null) {
            $filtered = apply_filters('meta_writer_prompt', $messages, $vars, $post);
            if (self::validMessages($filtered)) {
                /** @var list<array{role: string, content: string}> $filtered */
                $messages = $filtered;
            }
        }
        if ($retryNote !== '') {
            $messages[] = ['role' => 'user', 'content' => $retryNote];
        }

        return [$messages, $version];
    }

    /**
     * @param array<string, string|int> $vars
     * @return list<array{role: string, content: string}>
     */
    public static function builtin(array $vars): array
    {
        return [
            ['role' => 'system', 'content' => self::fill(self::systemText(), $vars)],
            ['role' => 'user', 'content' => self::fill(self::userText(), $vars)],
        ];
    }

    /** @param array<string, string|int> $vars */
    public static function fill(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function (array $m) use ($vars): string {
            return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0];
        }, $template);
    }

    /**
     * Render through prompt-library when it is active. A failure logs a
     * warning and returns null so the built-in prompt is used.
     *
     * @param array<string, string|int> $vars
     * @return array{0: list<array{role: string, content: string}>, 1: string}|null
     */
    private function fromLibrary(array $vars): ?array
    {
        if (!has_filter('prompt_library_render')) {
            return null;
        }
        try {
            $out = apply_filters('prompt_library_render', null, self::CONSUMER, $vars);
        } catch (\Throwable $e) {
            $this->log->warning('prompt-library render failed; using the built-in prompt.', ['event' => 'prompt_fallback', 'error' => (new \ReflectionClass($e))->getShortName()]);

            return null;
        }
        $messages = is_array($out) && isset($out['messages']) ? $out['messages'] : $out;
        if (!self::validMessages($messages)) {
            $this->log->warning('prompt-library gave no usable prompt; using the built-in prompt.', ['event' => 'prompt_fallback']);

            return null;
        }
        $version = is_array($out) && isset($out['version']) && is_scalar($out['version']) ? 'library-' . $out['version'] : 'library';

        /** @var list<array{role: string, content: string}> $messages */
        return [$messages, $version];
    }

    private static function validMessages(mixed $messages): bool
    {
        if (!is_array($messages) || $messages === [] || !array_is_list($messages)) {
            return false;
        }
        foreach ($messages as $m) {
            if (!is_array($m) || !in_array($m['role'] ?? null, ['system', 'user', 'assistant', 'developer'], true) || !is_string($m['content'] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
