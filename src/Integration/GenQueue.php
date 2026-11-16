<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Integration;

/**
 * Optional gen-queue integration (case 56), against its public contract.
 *
 * Enqueue: apply_filters('gen_queue_enqueue_job', null, $type, $payload,
 * $args) returns a job id (int) when gen-queue took the job; null (no
 * queue) or a WP_Error means the caller runs the post directly.
 *
 * Handling: meta-writer registers its job type on the action
 * gen_queue_register_types with gen_queue_register_type(). gen-queue then
 * calls the handler with its Job and JobContext objects.
 */
final class GenQueue
{
    public const JOB_TYPE = 'meta_writer_generate';
    public const MAX_ATTEMPTS = 3;

    public function active(): bool
    {
        return defined('GEN_QUEUE_VERSION') || has_filter('gen_queue_enqueue_job') !== false;
    }

    public static function idempotencyKey(int $postId, string $inputHash): string
    {
        return 'meta-writer:' . $postId . ':' . $inputHash;
    }

    /**
     * @param array<string, mixed> $payload
     * @return bool true when the queue took the job
     */
    public function enqueue(int $postId, string $inputHash, array $payload): bool
    {
        $job = apply_filters(
            'gen_queue_enqueue_job',
            null,
            self::JOB_TYPE,
            ['post_id' => $postId] + $payload,
            ['idempotency_key' => self::idempotencyKey($postId, $inputHash), 'source' => 'meta-writer'],
        );

        return is_int($job) && $job > 0;
    }

    /**
     * Register the job type. Hooked to gen_queue_register_types.
     *
     * @param callable $handler fn(object $job, object $ctx): array
     */
    public static function registerType(callable $handler): void
    {
        if (!function_exists('gen_queue_register_type')) {
            return;
        }
        gen_queue_register_type(self::JOB_TYPE, $handler, [
            'max_attempts' => self::MAX_ATTEMPTS,
            'meter' => ['purpose' => SpendMeter::PURPOSE],
        ]);
    }
}
