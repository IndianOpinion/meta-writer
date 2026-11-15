<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Rest;

use IndianOpinion\MetaWriter\Api;
use IndianOpinion\MetaWriter\Capabilities;
use IndianOpinion\MetaWriter\Container;
use IndianOpinion\MetaWriter\Exception\MetaWriterException;
use IndianOpinion\MetaWriter\Field;
use IndianOpinion\MetaWriter\Status;

/**
 * REST routes under meta-writer/v1 (PRD 5.5). Every route has a
 * permission callback; none is public. Bulk generation is not exposed.
 */
final class RestController
{
    public const NS = 'meta-writer/v1';

    public function __construct(private readonly Container $c)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
    }

    public function routes(): void
    {
        $id = ['id' => ['type' => 'integer', 'required' => true, 'minimum' => 1]];
        register_rest_route(self::NS, '/suggestions', [
            'methods' => 'GET',
            'callback' => [$this, 'list'],
            'permission_callback' => fn (): bool => $this->c->caps->can(get_current_user_id(), Capabilities::REVIEW),
            'args' => [
                'status' => ['type' => 'string', 'enum' => Status::ALL, 'default' => Status::PENDING],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            ],
        ]);
        register_rest_route(self::NS, '/suggestions/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'show'],
            'permission_callback' => [$this, 'canReviewSuggestion'],
            'args' => $id,
        ]);
        register_rest_route(self::NS, '/suggestions/(?P<id>\d+)/accept', [
            'methods' => 'POST',
            'callback' => [$this, 'accept'],
            'permission_callback' => [$this, 'canReviewSuggestion'],
            'args' => $id + [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'confirm_stale' => ['type' => 'boolean', 'default' => false],
            ],
        ]);
        register_rest_route(self::NS, '/suggestions/(?P<id>\d+)/reject', [
            'methods' => 'POST',
            'callback' => [$this, 'reject'],
            'permission_callback' => [$this, 'canReviewSuggestion'],
            'args' => $id,
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/generate', [
            'methods' => 'POST',
            'callback' => [$this, 'generate'],
            'permission_callback' => fn (\WP_REST_Request $r): bool => $this->c->caps->can(get_current_user_id(), Capabilities::GENERATE, (int) $r['id']),
            'args' => $id + ['fields' => ['type' => 'string'], 'regenerate' => ['type' => 'boolean', 'default' => false]],
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/revert', [
            'methods' => 'POST',
            'callback' => [$this, 'revert'],
            'permission_callback' => fn (\WP_REST_Request $r): bool => $this->c->caps->can(get_current_user_id(), Capabilities::REVIEW, (int) $r['id']),
            'args' => $id + ['field' => ['type' => 'string', 'enum' => Field::ALL, 'required' => true]],
        ]);
    }

    public function canReviewSuggestion(\WP_REST_Request $r): bool
    {
        $s = $this->c->suggestions->find((int) $r['id']);
        $user = get_current_user_id();

        return $s === null ? $this->c->caps->can($user, Capabilities::REVIEW) : $this->c->caps->can($user, Capabilities::REVIEW, $s->postId);
    }

    public function list(\WP_REST_Request $r): \WP_REST_Response
    {
        $result = $this->c->suggestions->query(['status' => (string) $r['status'], 'page' => (int) $r['page'], 'per_page' => (int) $r['per_page']]);
        $user = get_current_user_id();
        $items = [];
        foreach ($result['items'] as $s) {
            if ($this->c->caps->can($user, Capabilities::REVIEW, $s->postId)) {
                $items[] = $s->toArray();
            }
        }
        $response = new \WP_REST_Response($items);
        $response->header('X-WP-Total', (string) $result['total']);

        return $response;
    }

    public function show(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $s = $this->c->suggestions->find((int) $r['id']);

        return $s === null ? new \WP_Error('meta_writer_not_pending', 'No such suggestion.', ['status' => 404]) : new \WP_REST_Response($s->toArray());
    }

    public function accept(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $edits = [];
        foreach (Field::ALL as $field) {
            if ($r->has_param($field) && is_string($r[$field])) {
                $edits[$field] = (string) $r[$field];
            }
        }
        try {
            $result = $this->c->reviewer->accept((int) $r['id'], $edits, ['confirm_stale' => (bool) $r['confirm_stale']], get_current_user_id());
        } catch (MetaWriterException $e) {
            return Api::toError($e);
        }

        return new \WP_REST_Response($result->toArray());
    }

    public function reject(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        try {
            return new \WP_REST_Response($this->c->reviewer->reject((int) $r['id'], get_current_user_id())->toArray());
        } catch (MetaWriterException $e) {
            return Api::toError($e);
        }
    }

    public function generate(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        try {
            $outcome = $this->c->generator->generate((int) $r['id'], [
                'fields' => Field::parseList($r->has_param('fields') ? (string) $r['fields'] : null),
                'regenerate' => (bool) $r['regenerate'],
                'dry_run' => false,
                'user_id' => get_current_user_id(),
                'allow_queue' => false,
            ]);
        } catch (MetaWriterException $e) {
            return Api::toError($e);
        }
        $result = Api::outcomeToResult($outcome);

        return $result instanceof \WP_Error ? $result : new \WP_REST_Response($result->toArray(), 201);
    }

    public function revert(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        try {
            $this->c->reviewer->revert((int) $r['id'], (string) $r['field'], get_current_user_id());
        } catch (MetaWriterException $e) {
            return Api::toError($e);
        }

        return new \WP_REST_Response(['reverted' => true]);
    }
}
