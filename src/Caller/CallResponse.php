<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Caller;

final class CallResponse
{
    /**
     * @param array<string, mixed> $data decoded JSON object
     */
    public function __construct(
        public readonly array $data,
        public readonly string $route = '',
        public readonly ?string $raw = null,
        public readonly ?float $cost = null,
    ) {
    }
}
