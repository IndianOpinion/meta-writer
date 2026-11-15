<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Caller;

use IndianOpinion\MetaWriter\Exception\CallFailedException;
use IndianOpinion\MetaWriter\Exception\CreditExhaustedException;

/**
 * One model call that returns decoded JSON. Implement this (or pass a
 * callable through the meta_writer_caller filter) to run without
 * indianopinion/llm-json-php.
 */
interface ModelCaller
{
    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed>                        $schema JSON schema with an object root
     * @throws CallFailedException       on any failure the caller gave up on
     * @throws CreditExhaustedException  when every provider is out of credit
     */
    public function call(array $messages, array $schema): CallResponse;

    /** A short label for the route, stored with the suggestion. */
    public function routeLabel(): string;
}
