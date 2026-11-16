<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

/**
 * Scheduled work: the on-publish trigger (cases 53 to 55), admin bulk
 * runs in time-boxed ticks, and the daily cleanup (5.7, 6.4).
 */
final class Cron
{
    public const SINGLE = 'meta_writer_generate_single';
    public const BULK = 'meta_writer_bulk_tick';
    public const CLEANUP = 'meta_writer_daily_cleanup';
    public const BULK_OPTION = 'meta_writer_bulk_job';
    public const RAW_DAYS = 14;
    public const COUNTER_DAYS = 7;

    public function __construct(private readonly Container $c)
    {
    }

    public function register(): void
    {
        add_action('transition_post_status', [$this, 'onTransition'], 10, 3);
        add_action(self::SINGLE, [$this, 'runSingle']);
        add_action(self::BULK, [$this, 'runBulkTick']);
        add_action(self::CLEANUP, [$this, 'cleanup']);
    }

    public static function schedule(): void
    {
        if (wp_next_scheduled(self::CLEANUP) === false) {
            wp_schedule_event(time() + 3600, 'daily', self::CLEANUP);
        }
    }

    public static function unschedule(): void
    {
        foreach ([self::CLEANUP, self::BULK] as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        wp_unschedule_hook(self::SINGLE);
    }

    /**
     * A post entering an enabled status from outside the set schedules one
     * job, a short delay later, never inline.
     */
    public function onTransition(string $new, string $old, \WP_Post $post): void
    {
        if (!$this->c->settings->bool('on_publish') || $new === $old) {
            return;
        }
        $statuses = $this->c->generator->enabledStatuses();
        if (!in_array($new, $statuses, true) || in_array($old, $statuses, true)) {
            return;
        }
        if (!$this->c->generator->isCandidate($post)) {
            return;
        }
        $args = [(int) $post->ID];
        if (wp_next_scheduled(self::SINGLE, $args) !== false) {
            return;
        }
        wp_schedule_single_event(time() + max(0, $this->c->settings->int('on_publish_delay')), self::SINGLE, $args);
    }

    /** Never throws out of cron (case 75). */
    public function runSingle(int $postId): void
    {
        try {
            $this->c->generator->generate((int) $postId, ['dry_run' => false]);
        } catch (\Throwable $e) {
            $this->c->log->error('Scheduled generation failed.', ['event' => 'cron_error', 'post_id' => (int) $postId, 'error' => (new \ReflectionClass($e))->getShortName()]);
        }
    }

    /**
     * Start an admin bulk run: it proceeds in cron ticks of
     * cron_time_budget seconds until it stops for any reason but time.
     *
     * @param array<string, mixed> $args
     */
    public function startBulk(array $args, int $userId): void
    {
        update_option(self::BULK_OPTION, ['args' => $args, 'user_id' => $userId, 'cursor' => null, 'started' => time(), 'ticks' => 0], false);
        wp_clear_scheduled_hook(self::BULK);
        wp_schedule_single_event(time(), self::BULK);
    }

    public function cancelBulk(): void
    {
        delete_option(self::BULK_OPTION);
        wp_clear_scheduled_hook(self::BULK);
    }

    public function runBulkTick(): void
    {
        $job = get_option(self::BULK_OPTION);
        if (!is_array($job)) {
            return;
        }
        try {
            /** @var array<string, mixed> $args */
            $args = is_array($job['args'] ?? null) ? $job['args'] : [];
            $report = $this->c->runner->run([
                'fields' => isset($args['fields']) && is_array($args['fields']) ? array_values(array_map('strval', $args['fields'])) : [],
                'post_types' => isset($args['post_types']) && is_array($args['post_types']) ? array_values(array_map('strval', $args['post_types'])) : [],
                'limit' => (int) ($args['limit'] ?? 0),
                'regenerate' => !empty($args['regenerate']),
                'order' => (string) ($args['order'] ?? 'oldest'),
                'from' => isset($job['cursor']) && is_string($job['cursor']) ? $job['cursor'] : null,
                'time' => (float) max(5, $this->c->settings->int('cron_time_budget')),
                'dry_run' => false,
                'user_id' => (int) ($job['user_id'] ?? 0),
            ], static function (): void {
            });
        } catch (\Throwable $e) {
            $this->c->log->error('Bulk tick failed.', ['event' => 'cron_error', 'error' => (new \ReflectionClass($e))->getShortName()]);
            delete_option(self::BULK_OPTION);

            return;
        }
        if ($report->stopReason === Reason::TIME) {
            $job['cursor'] = $report->nextCursor;
            $job['ticks'] = (int) ($job['ticks'] ?? 0) + 1;
            update_option(self::BULK_OPTION, $job, false);
            wp_schedule_single_event(time() + 5, self::BULK);

            return;
        }
        delete_option(self::BULK_OPTION);
    }

    public function cleanup(): void
    {
        try {
            $now = (int) $this->c->clock->now();
            $day = gmdate('Y-m-d', strtotime($this->c->clock->siteDay() . ' -' . self::COUNTER_DAYS . ' days') ?: $now);
            $this->c->counters->purgeBefore($day);
            $days = max(1, $this->c->settings->int('retention_days'));
            $this->c->suggestions->purge($now - $days * 86400, [Status::REJECTED, Status::FAILED, Status::SUPERSEDED, Status::STALE]);
            $this->c->suggestions->clearRaw($now - self::RAW_DAYS * 86400);
        } catch (\Throwable $e) {
            $this->c->log->error('Daily cleanup failed.', ['event' => 'cron_error', 'error' => (new \ReflectionClass($e))->getShortName()]);
        }
    }
}
