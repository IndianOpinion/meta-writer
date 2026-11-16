<?php

// Minimal stand-ins for the public contracts of the optional companion
// plugins (gen-queue, prompt-library), for the tests and for static
// analysis. Only the names and signatures meta-writer uses.

declare(strict_types=1);

namespace IndianOpinion\GenQueue {
    class GenQueueException extends \RuntimeException
    {
    }

    class RetryableException extends GenQueueException
    {
        public function __construct(string $message = '', public readonly ?int $retryAfter = null)
        {
            parent::__construct($message);
        }
    }

    class AccountException extends GenQueueException
    {
    }
}

namespace {
    final class CompanionState
    {
        /** @var list<array{0: string, 1: mixed, 2: array<string, mixed>}> */
        public static array $queueTypes = [];
        /** @var list<array{0: string, 1: string, 2: list<string>}> */
        public static array $consumers = [];
        /** @var list<array{0: string, 1: array<mixed>}> */
        public static array $defaults = [];

        public static function reset(): void
        {
            self::$queueTypes = [];
            self::$consumers = [];
            self::$defaults = [];
        }
    }

    /** @param array<string, mixed> $args */
    function gen_queue_register_type(string $type, mixed $handler, array $args = []): void
    {
        CompanionState::$queueTypes[] = [$type, $handler, $args];
    }

    /** @param list<string> $supplied_names */
    function promptlib_register_consumer(string $slug, string $consumer_id, array $supplied_names): void
    {
        CompanionState::$consumers[] = [$slug, $consumer_id, $supplied_names];
    }

    /** @param array<mixed> $definition */
    function promptlib_register_default(string $consumer_id, array $definition): void
    {
        CompanionState::$defaults[] = [$consumer_id, $definition];
    }
}
