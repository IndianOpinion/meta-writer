<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Caller;

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Store\WordPressTransientStore;
use IndianOpinion\LlmJson\Transport\WordPressTransport;
use IndianOpinion\MetaWriter\Settings;

/**
 * Builds the caller. Order: a site caller from meta_writer_caller; else an
 * llm-json-php client (meta_writer_llm_client may replace it); else null,
 * which means generation is disabled (case 79).
 */
final class CallerFactory
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public static function libraryAvailable(): bool
    {
        return class_exists(Client::class);
    }

    public function make(): ?ModelCaller
    {
        $custom = apply_filters('meta_writer_caller', null);
        if ($custom instanceof ModelCaller) {
            return $custom;
        }
        if (is_callable($custom)) {
            return new CallableCaller($custom);
        }
        if (!self::libraryAvailable()) {
            return null;
        }
        try {
            $client = apply_filters('meta_writer_llm_client', $this->buildClient());
        } catch (\Throwable $e) {
            // A client that cannot be built means no generation (fail closed).
            return null;
        }
        if (!$client instanceof Client) {
            return null;
        }

        return new LlmJsonCaller($client, $this->label(), $this->settings->bool('keep_raw'));
    }

    public function label(): string
    {
        $model = $this->settings->string('model');

        return $model === '' ? '' : $this->settings->string('provider') . '/' . $model;
    }

    /** True when a model and (unless keyless) a key are configured. */
    public function configured(): bool
    {
        if ($this->settings->string('model') === '') {
            return false;
        }

        return $this->settings->string('provider') === 'generic' || $this->settings->apiKey() !== '';
    }

    private function buildClient(): ?Client
    {
        if (!$this->configured()) {
            return null;
        }
        $key = $this->settings->apiKey();
        if ($this->settings->string('provider') === 'generic' && !Settings::safeBaseUrl($this->settings->string('base_url'))) {
            return null;
        }
        $provider = match ($this->settings->string('provider')) {
            'openai' => Provider::openAi($key),
            'generic' => Provider::generic('generic', $this->settings->string('base_url'), $key, $key === ''),
            default => Provider::openRouter($key, home_url('/'), 'meta-writer'),
        };
        $routes = [Route::to($provider, $this->settings->string('model'))];
        $fallback = $this->settings->string('fallback_model');
        if ($fallback !== '') {
            $routes[] = Route::to($provider, $fallback);
        }

        return Client::create(Chain::of(...$routes), new WordPressTransport())
            ->withStore(new WordPressTransientStore());
    }
}
