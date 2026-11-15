<?php

/**
 * Plugin Name: meta-writer custom caller (example)
 * Description: Runs meta-writer without indianopinion/llm-json-php by
 *              supplying a caller. Copy into wp-content/mu-plugins/ and
 *              replace the body with your own HTTP call.
 */

declare(strict_types=1);

add_filter('meta_writer_caller', static function () {
    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed>                        $schema
     * @return array{title: string, description: string}
     */
    return static function (array $messages, array $schema): array {
        $response = wp_remote_post('https://llm.example.internal/v1/chat/completions', [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . (defined('MY_LLM_KEY') ? MY_LLM_KEY : '')],
            'body' => (string) wp_json_encode([
                'model' => 'my-model',
                'messages' => $messages,
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'title_description', 'strict' => true, 'schema' => $schema]],
            ]),
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Any exception marks the suggestion failed; the run moves on.
            throw new RuntimeException('LLM endpoint failed');
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $data = json_decode((string) ($body['choices'][0]['message']['content'] ?? ''), true);
        if (!is_array($data)) {
            throw new RuntimeException('LLM returned no JSON');
        }

        return ['title' => (string) ($data['title'] ?? ''), 'description' => (string) ($data['description'] ?? '')];
    };
});
