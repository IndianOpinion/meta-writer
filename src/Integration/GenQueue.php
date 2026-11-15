<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Integration;

/**
 * Optional gen-queue integration (case 56). A job is offered through the
 * gen_queue_enqueue_job filter; a null answer means no queue, so the
 * caller runs directly. Jobs come back through the action
 * gen_queue_handle_meta_writer_generate with the payload array.
 */
final class GenQueue
{
    public const JOB_TYPE = 'meta_writer_generate';
    public const HANDLER_ACTION = 'gen_queue_handle_' . self::JOB_TYPE;

    public function active(): bool
    {
        return has_filter('gen_queue_enqueue_job') !== false;
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
        $job = apply_filters('gen_queue_enqueue_job', null, [
            'type' => self::JOB_TYPE,
            'payload' => ['post_id' => $postId] + $payload,
            'idempotency_key' => self::idempotencyKey($postId, $inputHash),
        ]);

        return $job !== null && $job !== false && !is_wp_error($job);
    }
}
