<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

use IndianOpinion\MetaWriter\Adapter\AdapterRegistry;
use IndianOpinion\MetaWriter\Adapter\TargetAdapter;
use IndianOpinion\MetaWriter\Clock\Clock;
use IndianOpinion\MetaWriter\Exception\ChangedSinceWriteException;
use IndianOpinion\MetaWriter\Exception\ForbiddenException;
use IndianOpinion\MetaWriter\Exception\InvalidException;
use IndianOpinion\MetaWriter\Exception\NotPendingException;
use IndianOpinion\MetaWriter\Exception\PostMissingException;
use IndianOpinion\MetaWriter\Exception\StaleException;
use IndianOpinion\MetaWriter\Store\SuggestionStore;

/**
 * Accept, reject, revert and migrate (cases 30 to 42, 63, 66, 67).
 *
 * The one rule: a manual value is never overwritten, cleared or edited.
 * Writing fails closed. The kill switch does not apply here (case 51).
 */
final class Reviewer
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AdapterRegistry $adapters,
        private readonly Provenance $provenance,
        private readonly SuggestionStore $store,
        private readonly Capabilities $caps,
        private readonly Validator $validator,
        private readonly Log $log,
        private readonly Clock $clock,
    ) {
    }

    public function adapter(): TargetAdapter
    {
        return $this->adapters->resolve($this->settings->string('target'));
    }

    /**
     * @param array{title?: string, description?: string} $edits
     * @param array{confirm_stale?: bool, bulk?: bool}     $args
     */
    public function accept(int $suggestionId, array $edits, array $args, int $userId): AcceptResult
    {
        $s = $this->store->find($suggestionId);
        if ($s === null) {
            throw new NotPendingException(sprintf('Suggestion %d does not exist.', $suggestionId), 'missing');
        }
        $post = get_post($s->postId);
        if (!$post instanceof \WP_Post) {
            throw new PostMissingException(sprintf('Post %d no longer exists.', $s->postId), 'post_missing');
        }
        if (!$this->caps->can($userId, Capabilities::REVIEW, $s->postId)) {
            throw new ForbiddenException('You may not review suggestions for this post.', 'review');
        }
        if (!in_array($s->status, [Status::PENDING, Status::STALE], true)) {
            throw new NotPendingException(sprintf('Suggestion %d is %s, not pending.', $s->id, $s->status), $s->status);
        }
        $adapter = $this->adapter();

        // Case 38: has the post changed since the suggestion was made?
        $input = Input::fromPost($post, $this->settings);
        $stale = $s->status === Status::STALE || !hash_equals($s->inputHash, $input->hash());
        if ($stale) {
            if ($s->status === Status::PENDING) {
                $this->store->transition($s->id, [Status::PENDING], Status::STALE);
            }
            if (!empty($args['bulk']) || empty($args['confirm_stale'])) {
                throw new StaleException(sprintf('Post %d changed after suggestion %d was made. Read it again and confirm, or regenerate.', $s->postId, $s->id), 'stale');
            }
        }

        // Inline edits: same rules as model output, without the retry.
        $texts = ['title' => $s->title, 'description' => $s->description];
        $lengths = ['title' => $s->titleLength, 'description' => $s->descriptionLength];
        foreach (Field::ALL as $field) {
            if (!isset($edits[$field]) || !$s->hasField($field)) {
                continue;
            }
            $fit = $this->validator->check($field, (string) $edits[$field], $post, $input->language, $field === Field::DESCRIPTION ? ($edits['title'] ?? $s->title) : '');
            if (!$fit->ok) {
                throw new InvalidException(sprintf('The edited %s was refused: %s.', $field, Validator::describe((string) $fit->reason)), (string) $fit->reason);
            }
            $texts[$field] = $fit->text;
            $lengths[$field] = $fit->length;
        }

        // Case 66: compare-and-set; only one request wins.
        $now = (int) $this->clock->now();
        $won = $this->store->transition($s->id, [Status::PENDING, Status::STALE], Status::ACCEPTED, [
            'title' => $texts['title'],
            'description' => $texts['description'],
            'title_len' => $lengths['title'],
            'description_len' => $lengths['description'],
            'description_hash' => $texts['description'] === '' ? '' : Generator::descriptionHash($texts['description']),
            'decided_by' => $userId,
            'decided_at' => $now,
        ]);
        if (!$won) {
            throw new NotPendingException(sprintf('Suggestion %d was decided by someone else.', $s->id), 'race');
        }

        // Case 37: re-read each target immediately before writing it.
        $templateAsEmpty = $this->settings->bool('template_as_empty');
        $written = [];
        $superseded = [];
        $skipped = [];
        foreach ($s->fields as $field) {
            $text = $texts[$field];
            if ($text === '') {
                $skipped[$field] = 'empty';
                continue;
            }
            $state = $this->provenance->classify($s->postId, $field, $adapter, $templateAsEmpty);
            if ($state === 'manual') {
                $superseded[] = $field;
                continue;
            }
            $previous = $adapter->read($s->postId, $field);
            $adapter->write($s->postId, $field, $text);
            $this->provenance->record($s->postId, $field, $text, $previous, $userId, $adapter->id(), $s->id, $now);
            $written[] = $field;
        }
        if ($written === []) {
            $this->store->transition($s->id, [Status::ACCEPTED], Status::SUPERSEDED, ['error_message' => 'target filled before accept']);
        } elseif ($superseded !== []) {
            $this->store->transition($s->id, [Status::ACCEPTED], Status::ACCEPTED, ['error_message' => 'superseded: ' . implode(',', $superseded)]);
        }
        if ($written !== []) {
            clean_post_cache($s->postId);
        }

        $result = new AcceptResult($written, $superseded, $skipped);
        $fresh = $this->store->find($s->id) ?? $s;
        $this->log->info(sprintf('Suggestion %d accepted.', $s->id), ['event' => 'accepted', 'post_id' => $s->postId, 'user' => $userId, 'written' => implode(',', $written), 'superseded' => implode(',', $superseded)]);
        do_action('meta_writer_accepted', $fresh, $result, $userId);

        return $result;
    }

    public function reject(int $suggestionId, int $userId): Suggestion
    {
        $s = $this->store->find($suggestionId);
        if ($s === null) {
            throw new NotPendingException(sprintf('Suggestion %d does not exist.', $suggestionId), 'missing');
        }
        if (!$this->caps->can($userId, Capabilities::REVIEW, $s->postId)) {
            throw new ForbiddenException('You may not review suggestions for this post.', 'review');
        }
        $won = $this->store->transition($s->id, [Status::PENDING, Status::STALE], Status::REJECTED, ['decided_by' => $userId, 'decided_at' => (int) $this->clock->now()]);
        if (!$won) {
            throw new NotPendingException(sprintf('Suggestion %d is %s, not pending.', $s->id, $s->status), $s->status);
        }
        $fresh = $this->store->find($s->id) ?? $s;
        $this->log->info(sprintf('Suggestion %d rejected.', $s->id), ['event' => 'rejected', 'post_id' => $s->postId, 'user' => $userId]);
        do_action('meta_writer_rejected', $fresh, $userId);

        return $fresh;
    }

    /** Case 41: restore the previous value only while the target still holds ours. */
    public function revert(int $postId, string $field, int $userId): void
    {
        Field::assert($field);
        if (!get_post($postId) instanceof \WP_Post) {
            throw new PostMissingException(sprintf('Post %d no longer exists.', $postId), 'post_missing');
        }
        if (!$this->caps->can($userId, Capabilities::REVIEW, $postId)) {
            throw new ForbiddenException('You may not revert this post.', 'review');
        }
        $adapter = $this->adapter();
        $record = $this->provenance->last($postId, $field, $adapter->id());
        if ($record === null) {
            throw new InvalidException(sprintf('meta-writer has not written the %s of post %d.', $field, $postId), 'nothing_to_revert');
        }
        $current = $adapter->read($postId, $field);
        if (!$this->provenance->isOurs($postId, $field, $current, $adapter->id())) {
            throw new ChangedSinceWriteException(sprintf('The %s of post %d was changed after meta-writer wrote it; it is left alone.', $field, $postId), 'changed_since_write');
        }
        $previous = is_string($record['previous'] ?? null) ? $record['previous'] : '';
        if ($previous === '') {
            $adapter->clear($postId, $field);
        } else {
            $adapter->write($postId, $field, $previous);
        }
        $this->provenance->markReverted($postId, $field, $adapter->id(), $userId, (int) $this->clock->now());
        clean_post_cache($postId);
        $this->log->info(sprintf('Reverted the %s of post %d.', $field, $postId), ['event' => 'reverted', 'post_id' => $postId, 'user' => $userId]);
        do_action('meta_writer_reverted', $postId, $field, $userId);
    }

    /**
     * Copy fallback values into another adapter's empty fields (case 63).
     * Never writes over a non-empty value.
     *
     * @param list<int> $postIds
     * @return list<array{post_id: int, field: string, action: string}>
     */
    public function migrate(TargetAdapter $from, TargetAdapter $to, array $postIds, bool $apply, int $userId): array
    {
        $plan = [];
        $now = (int) $this->clock->now();
        $templateAsEmpty = $this->settings->bool('template_as_empty');
        foreach ($postIds as $postId) {
            foreach (Field::ALL as $field) {
                $value = $from->read($postId, $field);
                if (trim($value) === '') {
                    continue;
                }
                $state = $this->provenance->classify($postId, $field, $to, $templateAsEmpty);
                if ($state !== 'empty') {
                    $plan[] = ['post_id' => $postId, 'field' => $field, 'action' => 'kept_' . $state];
                    continue;
                }
                $plan[] = ['post_id' => $postId, 'field' => $field, 'action' => $apply ? 'copied' : 'would_copy'];
                if ($apply) {
                    $previous = $to->read($postId, $field);
                    $to->write($postId, $field, $value);
                    $this->provenance->record($postId, $field, $value, $previous, $userId, $to->id(), 0, $now);
                    clean_post_cache($postId);
                }
            }
        }

        return $plan;
    }
}
