<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

use IndianOpinion\MetaWriter\Exception\ForbiddenException;
use IndianOpinion\MetaWriter\Exception\MetaWriterException;
use IndianOpinion\MetaWriter\Exception\TargetUnavailableException;
use IndianOpinion\MetaWriter\Text\Fitter;
use IndianOpinion\MetaWriter\Text\Length;

/**
 * The public PHP API behind the meta_writer_* functions (PRD 5.1).
 * Exceptions become WP_Error here and nowhere else.
 */
final class Api
{
    public static function toError(MetaWriterException $e): \WP_Error
    {
        return new \WP_Error($e->errorCode(), $e->getMessage(), ['reason' => $e->reason(), 'status' => self::httpStatus($e->errorCode())]);
    }

    public static function httpStatus(string $code): int
    {
        return match ($code) {
            'meta_writer_forbidden' => 403,
            'meta_writer_post_missing' => 404,
            'meta_writer_stale', 'meta_writer_not_pending', 'meta_writer_changed_since_write' => 409,
            'meta_writer_invalid' => 422,
            'meta_writer_disabled', 'meta_writer_capped', 'meta_writer_spend_denied', 'meta_writer_target_unavailable' => 503,
            default => 400,
        };
    }

    /**
     * @param array{fields?: string|list<string>, regenerate?: bool, refresh_own?: bool, dry_run?: bool} $args
     */
    public static function generate(int $postId, array $args = []): Suggestion|\WP_Error
    {
        $c = Container::get();
        $user = get_current_user_id();
        try {
            if (!$c->caps->can($user, Capabilities::GENERATE, $postId)) {
                throw new ForbiddenException('You may not generate suggestions for this post.', 'generate');
            }
            $outcome = $c->generator->generate($postId, [
                'fields' => Field::parseList($args['fields'] ?? null),
                'regenerate' => !empty($args['regenerate']),
                'refresh_own' => !empty($args['refresh_own']),
                'dry_run' => !empty($args['dry_run']),
                'user_id' => $user,
                'allow_queue' => false,
            ]);
        } catch (MetaWriterException $e) {
            return self::toError($e);
        }

        return self::outcomeToResult($outcome);
    }

    public static function outcomeToResult(Outcome $o): Suggestion|\WP_Error
    {
        $data = ['reason' => $o->reason, 'fields' => $o->fields, 'input_length' => $o->inputLength];
        switch ($o->kind) {
            case Outcome::GENERATED:
                if ($o->suggestion !== null) {
                    return $o->suggestion;
                }
                return new \WP_Error('meta_writer_storage', 'The suggestion was made but could not be read back.', $data);
            case Outcome::PLANNED:
                return new \WP_Error('meta_writer_dry_run', 'Dry run: a real run would make one model call for this post.', $data);
            case Outcome::QUEUED:
                return new \WP_Error('meta_writer_queued', 'The post was queued for generation.', $data);
            case Outcome::SKIPPED:
                return new \WP_Error('meta_writer_skipped', 'Skipped: ' . $o->reason, $data);
            case Outcome::FAILED:
                $data['suggestion'] = $o->suggestion !== null ? $o->suggestion->id : 0;
                return new \WP_Error('meta_writer_invalid', 'Generation failed: ' . $o->reason, $data + ['status' => 502]);
            default:
                $code = match ($o->reason) {
                    Reason::CAPPED => 'meta_writer_capped',
                    Reason::SPEND_DENIED => 'meta_writer_spend_denied',
                    Reason::TARGET_UNAVAILABLE => 'meta_writer_target_unavailable',
                    Reason::CREDIT_EXHAUSTED => 'meta_writer_credit_exhausted',
                    default => 'meta_writer_disabled',
                };
                return new \WP_Error($code, 'Stopped: ' . $o->reason . ($o->message !== '' ? ' (' . $o->message . ')' : ''), $data + ['status' => 503]);
        }
    }

    /**
     * @param array{title?: string, description?: string} $edits
     * @param array{confirm_stale?: bool}                  $args
     */
    public static function accept(int $suggestionId, array $edits = [], array $args = []): AcceptResult|\WP_Error
    {
        try {
            return Container::get()->reviewer->accept($suggestionId, $edits, ['confirm_stale' => !empty($args['confirm_stale']), 'bulk' => !empty($args['bulk'])], get_current_user_id());
        } catch (MetaWriterException $e) {
            return self::toError($e);
        }
    }

    public static function reject(int $suggestionId): bool|\WP_Error
    {
        try {
            Container::get()->reviewer->reject($suggestionId, get_current_user_id());

            return true;
        } catch (MetaWriterException $e) {
            return self::toError($e);
        }
    }

    public static function revert(int $postId, string $field): bool|\WP_Error
    {
        try {
            Container::get()->reviewer->revert($postId, $field, get_current_user_id());

            return true;
        } catch (MetaWriterException $e) {
            return self::toError($e);
        }
    }

    public static function isManual(int $postId, string $field): bool
    {
        $c = Container::get();
        try {
            $adapter = $c->adapters->resolve($c->settings->string('target'));
        } catch (TargetUnavailableException $e) {
            return true; // Fail closed: when in doubt, the value is not ours.
        }

        return $c->provenance->classify($postId, Field::assert($field), $adapter, $c->settings->bool('template_as_empty')) === 'manual';
    }

    public static function measure(string $text): int
    {
        return Length::measure($text);
    }

    public static function fit(string $text, int $min, int $max, string $field): FitResult
    {
        return Fitter::fit($text, $min, $max, $field);
    }

    public static function isEnabled(): bool
    {
        $c = Container::get();

        return !$c->kill->isOn() && $c->canGenerate();
    }

    public static function capRemaining(): int
    {
        return Container::get()->cap->remaining();
    }
}
