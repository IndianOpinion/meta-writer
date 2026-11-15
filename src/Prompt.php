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
    /** The prompt-library template slug. */
    public const SLUG = 'meta-writer-title-description';
    public const BUILTIN_VERSION = 'builtin-1';
    /** Output token cap per call; also the spend meter's max_output_tokens. */
    public const MAX_OUTPUT_TOKENS = 600;
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

    /**
     * Tell prompt-library which variables meta-writer supplies and ship the
     * built-in prompt as its default template. Runs on init, only when
     * prompt-library is active.
     */
    public static function register(): void
    {
        if (function_exists('promptlib_register_consumer')) {
            promptlib_register_consumer(self::SLUG, self::CONSUMER, self::VARIABLES);
        }
        if (function_exists('promptlib_register_default')) {
            promptlib_register_default(self::CONSUMER, self::definition());
        }
    }

    /**
     * The built-in prompt in prompt-library's definition format
     * (prompt-library/1). Also passed as the render fallback.
     *
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        $int = static fn (string $name, string $description): array => ['name' => $name, 'type' => 'integer', 'description' => $description];

        return [
            'format' => 'prompt-library/1',
            'slug' => self::SLUG,
            'title' => 'meta-writer: SEO title and meta description',
            'description' => 'Drafts one SEO title and one meta description for a post. The answer must be a JSON object with "title" and "description".',
            'system' => self::systemText(),
            'user' => self::userText(),
            'variables' => [
                ['name' => 'title', 'type' => 'string', 'escape' => 'line', 'description' => 'The post title.'],
                ['name' => 'excerpt', 'type' => 'text', 'required' => false, 'description' => 'The manual excerpt, often empty.'],
                ['name' => 'body', 'type' => 'text', 'description' => 'The cleaned post text, cut to the body budget.'],
                ['name' => 'language', 'type' => 'string', 'escape' => 'line', 'description' => 'BCP 47 language tag for the answer.'],
                $int('title_min', 'Minimum title length in characters.'),
                $int('title_max', 'Maximum title length in characters.'),
                $int('description_min', 'Minimum description length in characters.'),
                $int('description_max', 'Maximum description length in characters.'),
            ],
            'output' => ['mode' => 'json', 'schema' => self::schema()],
            'settings' => ['temperature' => null, 'token_cap' => self::MAX_OUTPUT_TOKENS],
            'note' => 'Installed by meta-writer ' . self::BUILTIN_VERSION,
        ];
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
     * Render through prompt-library when it is active, with the built-in
     * definition as its fallback. A WP_Error, an exception or an unusable
     * answer logs a warning and returns null so the built-in prompt is
     * used (case 58).
     *
     * @param array<string, string|int> $vars
     * @return array{0: list<array{role: string, content: string}>, 1: string}|null
     */
    private function fromLibrary(array $vars): ?array
    {
        if (!has_filter('promptlib_render')) {
            return null;
        }
        try {
            $out = apply_filters('promptlib_render', null, self::SLUG, $vars, 'live', ['consumer' => self::CONSUMER, 'fallback' => self::definition()]);
        } catch (\Throwable $e) {
            $this->log->warning('prompt-library render failed; using the built-in prompt.', ['event' => 'prompt_fallback', 'error' => (new \ReflectionClass($e))->getShortName()]);

            return null;
        }
        if ($out === null) {
            return null;
        }
        if (is_wp_error($out)) {
            $this->log->warning('prompt-library render failed; using the built-in prompt.', ['event' => 'prompt_fallback', 'error' => (string) $out->get_error_code()]);

            return null;
        }
        if (!is_array($out) || !is_string($out['user'] ?? null) || trim($out['user']) === '' || !is_string($out['system'] ?? '')) {
            $this->log->warning('prompt-library gave no usable prompt; using the built-in prompt.', ['event' => 'prompt_fallback']);

            return null;
        }
        $messages = [];
        $system = (string) ($out['system'] ?? '');
        if (trim($system) !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $out['user']];
        $number = isset($out['version']) && is_int($out['version']) ? $out['version'] : 0;
        $version = $number > 0 ? 'library-' . $number : self::BUILTIN_VERSION;

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
