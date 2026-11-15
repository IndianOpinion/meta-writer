<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Unit;

use IndianOpinion\MetaWriter\Container;
use IndianOpinion\MetaWriter\Cron;
use IndianOpinion\MetaWriter\Exception\CallFailedException;
use IndianOpinion\MetaWriter\Integration\GenQueue;
use IndianOpinion\MetaWriter\Outcome;
use IndianOpinion\MetaWriter\Plugin;
use IndianOpinion\MetaWriter\Reason;
use IndianOpinion\MetaWriter\Status;
use IndianOpinion\MetaWriter\Tests\Support\TestCase;

final class RunTest extends TestCase
{
    /** T37, cases 43 and 44 */
    public function testDryRunSpendsAndWritesNothingAndIsTheDefault(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->post($i);
        }
        update_post_meta(2, '_meta_writer_title', 'x manual');
        update_post_meta(2, '_meta_writer_description', 'y manual');
        $this->post(3, ['post_content' => '<p>Too short.</p>']);
        update_post_meta(4, '_meta_writer_skip', '1');
        $this->settings(['daily_cap' => 2]);
        $metaBefore = \WpState::$meta;

        $report = $this->c->runner->run([]);
        $this->assertTrue($report->dryRun, 'dry run is the default');
        $this->assertCount(0, $this->caller->calls);
        $this->assertSame(0, $this->c->cap->used());
        $this->assertSame([], $this->store->rows[1] ?? []);
        $this->assertSame($metaBefore, \WpState::$meta);
        $this->assertSame(6, $report->considered);
        $this->assertSame(['both_filled' => 1, 'too_little_content' => 1, 'excluded' => 1], $report->skipped);
        $this->assertSame(3, $report->calls, 'calls a real run would make');
        $this->assertSame(2, $report->fitsToday);
        $this->assertSame(Reason::EMPTY, $report->stopReason);
        $planned = array_values(array_filter($report->rows, static fn (array $r): bool => $r['outcome'] === Outcome::PLANNED));
        $this->assertSame('title,description', $planned[0]['fields']);
        $this->assertGreaterThan(0, $planned[0]['input_length']);
    }

    /** T38, case 45 */
    public function testEachStopReasonHasTheRightCursor(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post($i);
        }
        $this->settings(['daily_cap' => 2]);
        $r = $this->c->runner->run(['dry_run' => false]);
        $this->assertSame(Reason::CAPPED, $r->stopReason);
        $this->assertSame('2', $r->nextCursor);
        $this->assertTrue($r->blocked());

        $this->settings(['daily_cap' => 100]);
        $r = $this->c->runner->run(['dry_run' => false, 'from' => '2', 'limit' => 1]);
        $this->assertSame(Reason::LIMIT, $r->stopReason);
        $this->assertSame('3', $r->nextCursor);

        $this->caller->queue = [];
        $clock = $this->clock;
        add_action('meta_writer_suggestion_created', static function () use ($clock): void {
            $clock->advance(30);
        });
        $r = $this->c->runner->run(['dry_run' => false, 'from' => '3', 'time' => 25]);
        $this->assertSame(Reason::TIME, $r->stopReason);
        $this->assertSame('4', $r->nextCursor);

        $this->c->kill->set(true, 't');
        $this->post(6);
        $r = $this->c->runner->run(['dry_run' => false, 'from' => '4']);
        $this->assertSame(Reason::DISABLED, $r->stopReason);
        $this->assertSame('4', $r->nextCursor);
        $this->c->kill->set(false, 't');

        $r = $this->c->runner->run(['dry_run' => false, 'from' => '4']);
        $this->assertSame(Reason::EMPTY, $r->stopReason);
        $this->assertNull($r->nextCursor);
        $this->assertSame(Reason::EMPTY, get_option('meta_writer_last_run')['stop_reason']);
        $this->assertNotEmpty(\WpState::actionsNamed('meta_writer_run_finished'));
    }

    /** T39, cases 46 and 47 */
    public function testCapOfThreeWithTwoInterleavedRunsAllowsExactlyThree(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->post($i);
        }
        $this->settings(['daily_cap' => 3]);
        $other = new Container($this->store, $this->counters, $this->source, $this->clock);
        $this->caller->queue = [new CallFailedException('x', 'ServerErrorException')];
        $outcomes = [];
        foreach ([1, 2, 3, 4, 5, 6] as $i) {
            $runner = $i % 2 === 0 ? $other : $this->c;
            $outcomes[] = $runner->generator->generate($i, ['dry_run' => false])->kind;
        }
        $this->assertCount(3, $this->caller->calls);
        $this->assertSame(3, $this->c->cap->used());
        $this->assertSame([Outcome::FAILED, Outcome::GENERATED, Outcome::GENERATED, Outcome::STOPPED, Outcome::STOPPED, Outcome::STOPPED], $outcomes);
        $this->assertNotEmpty(\WpState::actionsNamed('meta_writer_cap_reached'));
        $this->assertSame(0, meta_writer_cap_remaining());
    }

    /** T40, cases 48 and 77 */
    public function testCounterFollowsTheSiteDay(): void
    {
        update_option('timezone_string', 'Asia/Kolkata');
        $this->clock->time = (float) strtotime('2026-11-14 18:00:00 UTC'); // 23:30 in Kolkata
        $this->assertSame('2026-11-14', $this->c->cap->day());
        $this->post(1);
        $this->suggestFor(1);
        $this->assertSame(1, $this->c->cap->used());
        $this->clock->advance(3600); // 00:30 on the 15th in Kolkata, still the 14th in UTC
        $this->assertSame('2026-11-15', $this->c->cap->day());
        $this->assertSame(0, $this->c->cap->used(), 'a new site day starts at zero');
    }

    /** T41, case 49 */
    public function testCapZeroMakesNoCallAndHugeCapsAreRejected(): void
    {
        $this->settings(['daily_cap' => 0]);
        $this->post(1);
        $this->assertSame(Reason::CAPPED, $this->c->generator->generate(1, ['dry_run' => false])->reason);
        $this->assertCount(0, $this->caller->calls);
        [, $errors] = $this->c->settings->validate(['daily_cap' => '100001']);
        $this->assertArrayHasKey('daily_cap', $errors);
        [$ok, $none] = $this->c->settings->validate(['daily_cap' => '100000']);
        $this->assertSame([], $none);
        $this->assertSame(100000, $ok['daily_cap']);
    }

    /** T43, cases 52 and 56 */
    public function testQueueModeEnqueuesAndTheHandlerRechecks(): void
    {
        $this->post(1);
        $jobs = [];
        add_filter('gen_queue_enqueue_job', static function ($none, string $type, array $payload, array $args) use (&$jobs) {
            $jobs[] = ['type' => $type, 'payload' => $payload, 'args' => $args];

            return count($jobs);
        }, 10, 4);
        $o = $this->c->generator->generate(1, ['dry_run' => false]);
        $this->assertSame(Outcome::QUEUED, $o->kind);
        $this->assertCount(0, $this->caller->calls);
        $this->assertSame(GenQueue::JOB_TYPE, $jobs[0]['type']);
        $this->assertSame(1, $jobs[0]['payload']['post_id']);
        $hash = \IndianOpinion\MetaWriter\Input::fromPost(get_post(1), $this->c->settings)->hash();
        $this->assertSame('meta-writer:1:' . $hash, $jobs[0]['args']['idempotency_key']);

        // The type is registered with gen-queue when it asks.
        do_action('gen_queue_register_types');
        GenQueue::registerType([Plugin::class, 'handleQueuedJob']);
        $this->assertSame(GenQueue::JOB_TYPE, \CompanionState::$queueTypes[0][0]);

        // gen-queue hands back its Job object; the handler rechecks the
        // kill switch and the cap, and a stop asks the queue to retry later.
        $job = (object) ['payload' => $jobs[0]['payload']];
        $this->c->kill->set(true, 't');
        try {
            Plugin::handleQueuedJob($job, null);
            $this->fail('a stop must be retryable');
        } catch (\IndianOpinion\GenQueue\RetryableException $e) {
            $this->assertStringContainsString(Reason::DISABLED, $e->getMessage());
        }
        $this->assertCount(0, $this->caller->calls);
        $this->c->kill->set(false, 't');
        $this->settings(['daily_cap' => 0]);
        try {
            Plugin::handleQueuedJob($job, null);
            $this->fail('a capped job must be retryable');
        } catch (\IndianOpinion\GenQueue\RetryableException $e) {
            $this->assertGreaterThanOrEqual(60, $e->retryAfter);
        }
        $this->assertCount(0, $this->caller->calls);
        $this->settings(['daily_cap' => 5]);
        $result = Plugin::handleQueuedJob($job, null);
        $this->assertSame(Outcome::GENERATED, $result['outcome']);
        $this->assertCount(1, $this->caller->calls);
        $this->assertSame(Status::PENDING, $this->store->latestForPost(1)?->status);

        // A WP_Error from the queue, or no queue at all, means run directly.
        remove_all_filters('gen_queue_enqueue_job');
        add_filter('gen_queue_enqueue_job', static fn () => new \WP_Error('gen_queue_enqueue_failed', 'x'), 10, 4);
        $this->post(3);
        $this->assertSame(Outcome::GENERATED, $this->c->generator->generate(3, ['dry_run' => false])->kind);
        remove_all_filters('gen_queue_enqueue_job');
        add_filter('gen_queue_enqueue_job', static fn () => null, 10, 4);
        $this->post(2);
        $this->assertSame(Outcome::GENERATED, $this->c->generator->generate(2, ['dry_run' => false])->kind);
    }

    /** T44, cases 53 to 55 */
    public function testFirstPublishSchedulesOneJobAndTheResultIsPending(): void
    {
        $this->settings(['on_publish' => true, 'on_publish_delay' => 60]);
        $cron = new Cron($this->c);
        $post = $this->post(1, ['post_status' => 'publish']);
        $cron->onTransition('publish', 'draft', $post);
        $cron->onTransition('publish', 'draft', $post);
        $cron->onTransition('publish', 'publish', $post);
        $jobs = array_values(array_filter(\WpState::$scheduled, static fn (array $e): bool => $e['hook'] === Cron::SINGLE));
        $this->assertCount(1, $jobs);
        $this->assertGreaterThanOrEqual(time() + 59, $jobs[0]['time']);
        $this->assertCount(0, $this->caller->calls, 'never inline in the save request');

        $cron->runSingle(1);
        $this->assertSame(Status::PENDING, $this->store->latestForPost(1)?->status);
        $this->assertSame('', $this->meta(1, '_meta_writer_title'));

        // Off by default, and the job obeys the kill switch.
        $this->settings(['on_publish' => false]);
        $cron = new Cron($this->c);
        $cron->onTransition('publish', 'draft', $this->post(2));
        $this->assertCount(1, array_filter(\WpState::$scheduled, static fn (array $e): bool => $e['hook'] === Cron::SINGLE));
        $this->c->kill->set(true, 't');
        $cron->runSingle(2);
        $this->assertNull($this->store->latestForPost(2));
    }

    /** T56, case 71 */
    public function testHugePostStaysWithinBudgetAndMemory(): void
    {
        $this->post(1, ['post_content' => str_repeat('<p>' . str_repeat('word ', 20) . '</p>', 5000)]);
        $this->assertGreaterThanOrEqual(500000, strlen(get_post(1)->post_content));
        $before = memory_get_usage();
        $this->suggestFor(1);
        $body = $this->caller->calls[0][1]['content'];
        $this->assertLessThan(6000 + 500, mb_strlen($body));
        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $before + 1);
    }

    /** T60, case 78 */
    public function testCounterLivesInTheStoreNotTheObjectCache(): void
    {
        $this->post(1);
        $this->suggestFor(1);
        // Simulate an object cache flush: every cached option disappears.
        foreach (array_keys(\WpState::$options[1]) as $name) {
            if (str_starts_with($name, 'meta_writer_calls_')) {
                unset(\WpState::$options[1][$name]);
            }
        }
        $this->rebuild();
        $this->assertSame(1, $this->c->cap->used());
    }

    public function testCleanupPurgesOldRowsAndRaw(): void
    {
        $this->post(1);
        $this->settings(['keep_raw' => true]);
        $this->caller->queue = [['title' => '', 'description' => ''], ['title' => '', 'description' => '']];
        $failed = $this->c->generator->generate(1, ['dry_run' => false])->suggestion;
        $this->assertNotNull($failed?->raw);
        $this->clock->advance(15 * 86400);
        (new Cron($this->c))->cleanup();
        $this->assertNull($this->store->find((int) $failed?->id)?->raw);
        $this->clock->advance(80 * 86400);
        (new Cron($this->c))->cleanup();
        $this->assertNull($this->store->find((int) $failed?->id));
    }
}
