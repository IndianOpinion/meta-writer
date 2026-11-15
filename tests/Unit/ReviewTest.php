<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Tests\Unit;

use IndianOpinion\MetaWriter\Adapter\AdapterRegistry;
use IndianOpinion\MetaWriter\Adapter\MetaKeyAdapter;
use IndianOpinion\MetaWriter\Container;
use IndianOpinion\MetaWriter\Exception\ChangedSinceWriteException;
use IndianOpinion\MetaWriter\Exception\ForbiddenException;
use IndianOpinion\MetaWriter\Exception\InvalidException;
use IndianOpinion\MetaWriter\Exception\NotPendingException;
use IndianOpinion\MetaWriter\Exception\StaleException;
use IndianOpinion\MetaWriter\Exception\TargetUnavailableException;
use IndianOpinion\MetaWriter\Provenance;
use IndianOpinion\MetaWriter\Reason;
use IndianOpinion\MetaWriter\Status;
use IndianOpinion\MetaWriter\Tests\Support\TestCase;
use IndianOpinion\MetaWriter\Uninstaller;

final class ReviewTest extends TestCase
{
    private const T = '_meta_writer_title';
    private const D = '_meta_writer_description';

    /** T26, cases 30 and 31 */
    public function testWriteRecordsProvenanceAndAOneCharacterEditMakesItManual(): void
    {
        $this->post(1);
        $s = $this->suggestFor(1);
        $r = $this->c->reviewer->accept($s->id, [], [], self::EDITOR);
        $this->assertSame(['title', 'description'], $r->written);
        $record = $this->c->provenance->last(1, 'title');
        $this->assertSame($s->title, $record['value']);
        $this->assertSame(hash('sha256', $s->title), $record['sha256']);
        $this->assertSame('', $record['previous']);
        $this->assertSame(self::EDITOR, $record['user']);
        $this->assertSame('fallback', $record['adapter']);
        $this->assertSame($s->id, $record['suggestion']);
        $this->assertFalse(meta_writer_is_manual(1, 'title'));
        $this->assertContains(1, \WpState::$cleaned);

        update_post_meta(1, self::T, $s->title . '!');
        $this->assertTrue(meta_writer_is_manual(1, 'title'));
    }

    /** T27, case 32 */
    public function testNoCodePathChangesAManualValue(): void
    {
        $manual = 'Written by a person, kept forever';
        $this->post(1);
        update_post_meta(1, self::T, $manual);
        $s = $this->suggestFor(1);
        $this->assertSame(['description'], $s->fields);
        // Someone types a description after generation.
        update_post_meta(1, self::D, 'A person wrote this description just now.');

        $this->c->reviewer->accept($s->id, [], [], self::EDITOR);
        $this->assertSame(Reason::BOTH_FILLED, $this->c->generator->generate(1, ['dry_run' => false, 'regenerate' => true, 'refresh_own' => true])->reason);
        try {
            $this->c->reviewer->revert(1, 'title', self::EDITOR);
            $this->fail('revert must refuse');
        } catch (InvalidException | ChangedSinceWriteException $e) {
            $this->assertTrue(true);
        }
        $to = new MetaKeyAdapter('other', 'Other', '_other_title', '_other_desc', static fn (): bool => true);
        update_post_meta(1, '_other_title', 'Manual in the other plugin');
        $this->c->reviewer->migrate($this->c->adapters->get('fallback'), $to, [1], true, self::EDITOR);
        $this->assertSame('Manual in the other plugin', $this->meta(1, '_other_title'));
        Uninstaller::site();
        $this->assertSame($manual, $this->meta(1, self::T));
        $this->assertSame('A person wrote this description just now.', $this->meta(1, self::D));
    }

    /** T28, case 33 */
    public function testOwnValueIsRegeneratedOnlyWithRefreshOwnAndThroughReview(): void
    {
        $this->post(1);
        $s = $this->suggestFor(1);
        $this->c->reviewer->accept($s->id, [], [], self::EDITOR);
        $this->assertSame(Reason::BOTH_FILLED, $this->c->generator->generate(1, ['dry_run' => false])->reason);
        $this->caller->queue = [['title' => 'A fresh title about the early monsoon in Kerala', 'description' => 'A fresh description: the monsoon reached Kerala early and farmers in several districts welcomed the rain.']];
        $new = $this->suggestFor(1, ['refresh_own' => true]);
        $this->assertSame(Status::PENDING, $new->status);
        $this->assertSame($s->title, $this->meta(1, self::T), 'no write before accept');
        $this->c->reviewer->accept($new->id, [], [], self::EDITOR);
        $this->assertSame('A fresh title about the early monsoon in Kerala', $this->meta(1, self::T));
    }

    /** T29, case 34 and ruling 4 */
    public function testTemplateOnlyValueIsManualUnlessOptedIn(): void
    {
        $yoast = new MetaKeyAdapter('yoast', 'Yoast SEO', '_yoast_wpseo_title', '_yoast_wpseo_metadesc', static fn (): bool => true, false, '/%%[a-z0-9_]+%%/i');
        $this->c = new Container($this->store, $this->counters, $this->source, $this->clock, new AdapterRegistry(['yoast' => $yoast]));
        Container::set($this->c);
        $this->post(1);
        update_post_meta(1, '_yoast_wpseo_title', '%%title%% %%sep%% %%sitename%%');
        $this->assertSame('manual', $this->c->provenance->classify(1, 'title', $yoast, false));
        $this->assertSame('empty', $this->c->provenance->classify(1, 'title', $yoast, true));
        update_post_meta(1, '_yoast_wpseo_title', '%%title%% about rain');
        $this->assertSame('manual', $this->c->provenance->classify(1, 'title', $yoast, true));
    }

    /** T30, cases 35 and 36 */
    public function testSuggestionStoresEverythingAndInlineEditsAreValidated(): void
    {
        $this->post(1);
        $s = $this->suggestFor(1);
        foreach (['id', 'postId', 'fields', 'title', 'description', 'titleLength', 'descriptionLength', 'flags', 'status', 'inputHash', 'route', 'promptVersion', 'createdAt'] as $prop) {
            $this->assertNotNull($s->$prop, $prop);
        }
        $this->assertSame(64, strlen($s->inputHash));
        $this->assertSame('fake/model', $s->route);
        $this->assertSame(mb_strlen($s->title), $s->titleLength);
        $this->assertSame((int) $this->clock->now(), $s->createdAt);

        try {
            $this->c->reviewer->accept($s->id, ['title' => 'Short'], [], self::EDITOR);
            $this->fail('a short edit must be refused');
        } catch (InvalidException $e) {
            $this->assertSame('too_short', $e->reason());
            $this->assertStringContainsString('too short', $e->getMessage());
        }
        $this->assertSame(Status::PENDING, $this->store->find($s->id)?->status);
        $r = $this->c->reviewer->accept($s->id, ['title' => '"An edited title about the Kerala monsoon"'], [], self::EDITOR);
        $this->assertContains('title', $r->written);
        $this->assertSame('An edited title about the Kerala monsoon', $this->meta(1, self::T));
    }

    /** T31, cases 37 and 67 */
    public function testTargetFilledBetweenGenerationAndAcceptIsSuperseded(): void
    {
        $this->post(1);
        $s = $this->suggestFor(1);
        update_post_meta(1, self::T, 'An editor typed this first');
        $r = $this->c->reviewer->accept($s->id, [], [], self::EDITOR);
        $this->assertSame(['title'], $r->superseded);
        $this->assertSame(['description'], $r->written);
        $this->assertSame('An editor typed this first', $this->meta(1, self::T));

        $this->post(2);
        $s2 = $this->suggestFor(2);
        update_post_meta(2, self::T, 'Manual title');
        update_post_meta(2, self::D, 'Manual description');
        $r2 = $this->c->reviewer->accept($s2->id, [], [], self::EDITOR);
        $this->assertSame([], $r2->written);
        $this->assertSame(Status::SUPERSEDED, $this->store->find($s2->id)?->status);
    }

    /** T32, case 38 */
    public function testChangedPostMakesSuggestionStale(): void
    {
        $this->post(1);
        $this->post(2);
        $s1 = $this->suggestFor(1);
        $s2 = $this->suggestFor(2);
        \WpState::$posts[1][1]->post_content .= '<p>An update was added later.</p>';
        \WpState::$posts[1][2]->post_content .= '<p>An update was added later.</p>';
        try {
            $this->c->reviewer->accept($s1->id, [], ['bulk' => true, 'confirm_stale' => true], self::EDITOR);
            $this->fail('bulk accept must refuse a stale suggestion');
        } catch (StaleException $e) {
            $this->assertSame(Status::STALE, $this->store->find($s1->id)?->status);
        }
        $this->expectException(StaleException::class);
        try {
            $this->c->reviewer->accept($s2->id, [], [], self::EDITOR);
        } finally {
            $r = $this->c->reviewer->accept($s1->id, [], ['confirm_stale' => true], self::EDITOR);
            $this->assertSame(['title', 'description'], $r->written);
        }
    }

    /** T34, case 40 */
    public function testRejectStoresUserAndStatus(): void
    {
        $this->post(1);
        $s = $this->suggestFor(1);
        $r = $this->c->reviewer->reject($s->id, self::EDITOR);
        $this->assertSame(Status::REJECTED, $r->status);
        $this->assertSame(self::EDITOR, $r->decidedBy);
        $this->assertSame($s->title, $r->title, 'text kept for audit');
        $this->assertCount(1, \WpState::actionsNamed('meta_writer_rejected'));
        $this->expectException(NotPendingException::class);
        $this->c->reviewer->reject($s->id, self::EDITOR);
    }

    /** T35, case 41 */
    public function testRevertWhileUnchangedAndRefuseAfterAnEdit(): void
    {
        $this->post(1);
        update_post_meta(1, self::T, '%%title%%');
        $this->settings(['template_as_empty' => false]);
        $s = $this->suggestFor(1);
        $this->c->reviewer->accept($s->id, [], [], self::EDITOR);
        $this->c->reviewer->revert(1, 'description', self::EDITOR);
        $this->assertSame('', $this->meta(1, self::D));
        $this->assertCount(1, \WpState::actionsNamed('meta_writer_reverted'));

        $this->post(2);
        $s2 = $this->suggestFor(2);
        $this->c->reviewer->accept($s2->id, [], [], self::EDITOR);
        update_post_meta(2, self::T, 'Changed by a person');
        try {
            $this->c->reviewer->revert(2, 'title', self::EDITOR);
            $this->fail('expected changed_since_write');
        } catch (ChangedSinceWriteException $e) {
            $this->assertSame('changed_since_write', $e->reason());
        }
        $this->assertSame('Changed by a person', $this->meta(2, self::T));
        $err = meta_writer_revert(2, 'title');
        $this->assertInstanceOf(\WP_Error::class, $err);
        $this->assertSame('meta_writer_changed_since_write', $err->get_error_code());
    }

    /** T36, case 42 */
    public function testCapabilities(): void
    {
        $this->post(1);
        $s = $this->suggestFor(1);
        \WpState::$caps[9] = [];
        foreach ([fn () => $this->c->reviewer->accept($s->id, [], [], 9), fn () => $this->c->reviewer->reject($s->id, 9), fn () => $this->c->reviewer->revert(1, 'title', 9)] as $call) {
            try {
                $call();
                $this->fail('expected forbidden');
            } catch (ForbiddenException $e) {
                $this->assertSame('meta_writer_forbidden', $e->errorCode());
            }
        }
        \WpState::$user = 9;
        $this->assertSame('meta_writer_forbidden', meta_writer_generate(1)->get_error_code());

        // Authors: only with allow_authors, and only for posts they can edit.
        $this->assertFalse($this->c->caps->can(self::AUTHOR, 'review', 1));
        $this->settings(['allow_authors' => true]);
        $this->assertFalse($this->c->caps->can(self::AUTHOR, 'review', 1));
        \WpState::$editable[self::AUTHOR] = [1];
        $this->assertTrue($this->c->caps->can(self::AUTHOR, 'review', 1));
        $this->assertTrue($this->c->caps->can(self::AUTHOR, 'generate', 1));
        $this->assertFalse($this->c->caps->can(self::AUTHOR, 'settings'));
        $this->assertTrue($this->c->caps->can(self::ADMIN, 'settings'));
        add_filter('meta_writer_capability', static fn (string $cap, string $action): string => $action === 'review' ? 'manage_options' : $cap, 10, 2);
        $this->assertFalse($this->c->caps->can(self::EDITOR, 'review', 1));
    }

    /** T42, case 51 */
    public function testKillSwitchDoesNotBlockReview(): void
    {
        $this->post(1);
        $this->post(2);
        $a = $this->suggestFor(1);
        $b = $this->suggestFor(2);
        $this->c->kill->set(true, 'test');
        $this->assertSame(['title', 'description'], $this->c->reviewer->accept($a->id, [], [], self::EDITOR)->written);
        $this->c->reviewer->reject($b->id, self::EDITOR);
        $this->c->reviewer->revert(1, 'title', self::EDITOR);
        $this->assertSame('', $this->meta(1, self::T));
    }

    /** T47, case 60 */
    public function testDeactivatedTargetBlocksAcceptAndPendingStays(): void
    {
        $this->post(1);
        $s = $this->suggestFor(1);
        $this->settings(['target' => 'yoast']);
        try {
            $this->c->reviewer->accept($s->id, [], [], self::EDITOR);
            $this->fail('expected target unavailable');
        } catch (TargetUnavailableException $e) {
            $this->assertSame('meta_writer_target_unavailable', $e->errorCode());
        }
        $this->assertSame(Status::PENDING, $this->store->find($s->id)?->status);
        $this->post(2);
        $this->assertSame(Reason::TARGET_UNAVAILABLE, $this->c->generator->generate(2, ['dry_run' => false])->reason);
    }

    /** T51, cases 64 and 65 */
    public function testDeletedPostDropsAndTrashedPostHides(): void
    {
        $this->post(1);
        $this->post(2);
        $a = $this->suggestFor(1);
        $this->suggestFor(2);
        \WpState::$posts[1][2]->post_status = 'trash';
        $this->assertSame(1, $this->store->query(['status' => Status::PENDING])['total']);
        \WpState::$posts[1][2]->post_status = 'publish';
        $this->assertSame(2, $this->store->query(['status' => Status::PENDING])['total']);

        $this->store->deleteForPost(1);
        unset(\WpState::$posts[1][1]);
        $this->assertNull($this->store->find($a->id));
        $s = $this->suggestFor(2, ['regenerate' => true]);
        unset(\WpState::$posts[1][2]);
        $err = meta_writer_accept($s->id);
        $this->assertSame('meta_writer_post_missing', $err instanceof \WP_Error ? $err->get_error_code() : '');
    }

    /** T52, case 66 */
    public function testTwoConcurrentAcceptsWriteOnce(): void
    {
        $this->post(1);
        $s = $this->suggestFor(1);
        // The second request loaded the row before the first wrote.
        $this->c->reviewer->accept($s->id, [], [], self::EDITOR);
        $writes = count(\WpState::$metaWrites);
        $this->assertFalse($this->store->transition($s->id, [Status::PENDING, Status::STALE], Status::ACCEPTED));
        try {
            $this->c->reviewer->accept($s->id, [], [], self::ADMIN);
            $this->fail('second accept must not write');
        } catch (NotPendingException $e) {
            $this->assertSame($writes, count(\WpState::$metaWrites));
        }
        $this->assertCount(1, \WpState::actionsNamed('meta_writer_accepted'));
    }

    public function testProvenanceKeepsFiveRecords(): void
    {
        $p = new Provenance();
        for ($i = 0; $i < 8; $i++) {
            $p->record(1, 'title', 'v' . $i, '', 1, 'fallback', $i, $i);
        }
        $this->assertCount(5, $p->all(1)['title']);
        $this->assertSame('v7', $p->last(1, 'title')['value']);
    }
}
