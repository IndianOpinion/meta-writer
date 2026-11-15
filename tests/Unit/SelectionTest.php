<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Unit;

use IndianOpinion\MetaWriter\Outcome;
use IndianOpinion\MetaWriter\Reason;
use IndianOpinion\MetaWriter\Settings;
use IndianOpinion\MetaWriter\Status;
use IndianOpinion\MetaWriter\Tests\Support\TestCase;

final class SelectionTest extends TestCase
{
    /** T1, case 1 */
    public function testEnabledTypeAndStatusIsACandidateAndOtherTypeIsNot(): void
    {
        $this->assertTrue($this->c->generator->isCandidate($this->post(1)));
        $this->assertTrue($this->c->generator->isCandidate($this->post(2, ['post_type' => 'page', 'post_status' => 'future'])));
        $this->assertFalse($this->c->generator->isCandidate($this->post(3, ['post_type' => 'product'])));
        $this->assertFalse($this->c->generator->isCandidate($this->post(4, ['post_password' => 'secret'])));
    }

    /** T2, case 2 */
    public function testDraftPrivateRevisionAndAutosaveAreNeverCandidates(): void
    {
        foreach (['draft', 'private', 'pending', 'trash'] as $i => $status) {
            $this->assertFalse($this->c->generator->isCandidate($this->post(10 + $i, ['post_status' => $status])), $status);
        }
        $this->assertFalse($this->c->generator->isCandidate($this->post(20, ['post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => 1])));
        $this->assertFalse($this->c->generator->isCandidate($this->post(21, ['post_type' => 'revision', 'post_status' => 'inherit', 'post_name' => '1-autosave-v1'])));

        // A revision can never be enabled: not by settings, not by filter.
        [$values, $errors] = $this->c->settings->validate(['post_types' => 'post,revision', 'post_statuses' => 'publish,inherit']);
        $this->assertArrayHasKey('post_types', $errors);
        $this->assertArrayHasKey('post_statuses', $errors);
        $this->assertSame('post,page', $values['post_types']);
        add_filter('meta_writer_post_types', static fn (array $t): array => array_merge($t, ['revision']));
        add_filter('meta_writer_post_statuses', static fn (array $s): array => array_merge($s, ['inherit']));
        $this->assertNotContains('revision', $this->c->generator->enabledTypes());
        $this->assertNotContains('inherit', $this->c->generator->enabledStatuses());
        $this->assertFalse($this->c->generator->isCandidate($this->post(22, ['post_type' => 'revision', 'post_status' => 'inherit'])));

        // Adding draft through settings works (operator's choice).
        $this->settings(['post_statuses' => 'publish,draft']);
        $this->assertTrue($this->c->generator->isCandidate($this->post(23, ['post_status' => 'draft'])));
    }

    /** T3, case 3 */
    public function testOptOutMetaAndFilterGiveExcludedWithNoCall(): void
    {
        $this->post(1);
        update_post_meta(1, '_meta_writer_skip', '1');
        $this->assertSame(Reason::EXCLUDED, $this->c->generator->generate(1, ['dry_run' => false])->reason);

        $this->post(2);
        add_filter('meta_writer_should_process', static fn (bool $yes, \WP_Post $p): bool => $p->ID !== 2, 10, 2);
        $this->assertSame(Reason::EXCLUDED, $this->c->generator->generate(2, ['dry_run' => false])->reason);
        $this->assertCount(0, $this->caller->calls);
    }

    /** T4, case 4 */
    public function testBothTargetsFilledSkipsWithoutACall(): void
    {
        $this->post(1);
        update_post_meta(1, '_meta_writer_title', 'A title a person wrote');
        update_post_meta(1, '_meta_writer_description', 'A description a person wrote.');
        $o = $this->c->generator->generate(1, ['dry_run' => false]);
        $this->assertSame(Reason::BOTH_FILLED, $o->reason);
        $this->assertCount(0, $this->caller->calls);
    }

    /** T5, case 5 */
    public function testOneEmptyFieldMakesOneCallAndStoresOnlyThatField(): void
    {
        $this->post(1);
        update_post_meta(1, '_meta_writer_title', 'A title a person wrote');
        $s = $this->suggestFor(1);
        $this->assertCount(1, $this->caller->calls);
        $this->assertSame(['description'], $s->fields);
        $this->assertSame('', $s->title);
        $this->assertNotSame('', $s->description);

        // The fields option restricts a run.
        $this->post(2);
        $s2 = $this->suggestFor(2, ['fields' => ['title']]);
        $this->assertSame(['title'], $s2->fields);
        $this->assertSame('', $s2->description);
    }

    /** T6, case 6 */
    public function testPendingSkipsAndRegenerateReplacesIt(): void
    {
        $this->post(1);
        $first = $this->suggestFor(1);
        $this->assertSame(Reason::PENDING_EXISTS, $this->c->generator->generate(1, ['dry_run' => false])->reason);
        $this->assertCount(1, $this->caller->calls);

        $second = $this->suggestFor(1, ['regenerate' => true]);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(Status::SUPERSEDED, $this->store->find($first->id)?->status);
        $this->assertSame(Status::PENDING, $second->status);
    }

    /** T7, case 7 */
    public function testRejectedSkipsUnlessRegenerate(): void
    {
        $this->post(1);
        $s = $this->suggestFor(1);
        $this->c->reviewer->reject($s->id, self::EDITOR);
        $this->assertSame(Reason::REJECTED_BEFORE, $this->c->generator->generate(1, ['dry_run' => false])->reason);
        $this->assertSame(Outcome::GENERATED, $this->c->generator->generate(1, ['dry_run' => false, 'regenerate' => true])->kind);
    }

    /** T8, cases 8 and 9 */
    public function testBatchesCoverIdsInOrderWithNoGapAndResumeExactly(): void
    {
        for ($i = 1; $i <= 250; $i++) {
            $this->post($i * 2);
        }
        $this->settings(['batch_size' => 100]);
        $seen = [];
        $report = $this->c->runner->run(['dry_run' => true], static function (array $row) use (&$seen): void {
            $seen[] = $row['id'];
        });
        $this->assertSame(range(2, 500, 2), $seen);
        $this->assertSame(3 + 1, $this->source->calls, 'three full pages and one empty page');
        $this->assertSame(Reason::EMPTY, $report->stopReason);

        // Resume from a cursor: limit stops the run; the next run continues exactly.
        $first = $this->c->runner->run(['dry_run' => true, 'limit' => 120]);
        $this->assertSame(Reason::LIMIT, $first->stopReason);
        $this->assertSame('240', $first->nextCursor);
        $rest = [];
        $this->c->runner->run(['dry_run' => true, 'from' => $first->nextCursor], static function (array $row) use (&$rest): void {
            $rest[] = $row['id'];
        });
        $this->assertSame(range(242, 500, 2), $rest);

        // Newest first is stable too.
        $newest = [];
        $this->c->runner->run(['dry_run' => true, 'order' => 'newest', 'limit' => 5], static function (array $row) use (&$newest): void {
            $newest[] = $row['id'];
        });
        $this->assertCount(5, $newest);
    }

    /** Settings are validated, never clamped. */
    public function testInvalidSettingsAreRejectedWithAMessage(): void
    {
        [$values, $errors] = $this->c->settings->validate(['title_max' => '500', 'description_min' => 'abc', 'daily_cap' => '-1']);
        $this->assertArrayHasKey('title_max', $errors);
        $this->assertArrayHasKey('description_min', $errors);
        $this->assertArrayHasKey('daily_cap', $errors);
        $this->assertSame(Settings::defaults()['title_max'], $values['title_max']);
    }
}
