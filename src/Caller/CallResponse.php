<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Caller;

final class CallResponse
{
    /**
     * @param array<string, mixed> $data decoded JSON object
     * @param string               $route "provider/model" that answered
     * @param float|null           $cost  billed cost when the provider reported it
     */
    public function __construct(
        public readonly array $data,
        public readonly string $route = '',
        public readonly ?string $raw = null,
        public readonly ?float $cost = null,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {
    }
}
