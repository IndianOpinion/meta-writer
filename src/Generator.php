<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

use IndianOpinion\MetaWriter\Adapter\AdapterRegistry;
use IndianOpinion\MetaWriter\Adapter\TargetAdapter;
use IndianOpinion\MetaWriter\Caller\ModelCaller;
use IndianOpinion\MetaWriter\Clock\Clock;
use IndianOpinion\MetaWriter\Exception\CallFailedException;
use IndianOpinion\MetaWriter\Exception\CreditExhaustedException;
use IndianOpinion\MetaWriter\Exception\StorageException;
use IndianOpinion\MetaWriter\Exception\TargetUnavailableException;
use IndianOpinion\MetaWriter\Integration\GenQueue;
use IndianOpinion\MetaWriter\Integration\SpendMeter;
use IndianOpinion\MetaWriter\Store\SuggestionStore;
use IndianOpinion\MetaWriter\Text\DetailCheck;

/**
 * Makes a suggestion for one post. Never writes a target.
 *
 * Gate order before every call (case 20): kill switch, cap headroom,
 * spend meter, then the atomic cap take. Generation fails closed.
 */
final class Generator
{
    public const RAW_KEEP = 2000;

    /** @var \Closure(): ?ModelCaller */
    private \Closure $callerFactory;
    private ?ModelCaller $caller = null;
    private bool $callerBuilt = false;

    /** @param callable(): ?ModelCaller $callerFactory */
    public function __construct(
        private readonly Settings $settings,
        private readonly AdapterRegistry $adapters,
        private readonly Provenance $provenance,
        private readonly SuggestionStore $store,
        private readonly KillSwitch $kill,
        private readonly DailyCap $cap,
        callable $callerFactory,
        private readonly Prompt $prompt,
        private readonly Validator $validator,
        private readonly SpendMeter $spend,
        private readonly GenQueue $queue,
        private readonly Log $log,
        private readonly Clock $clock,
    ) {
        $this->callerFactory = \Closure::fromCallable($callerFactory);
    }

    public function caller(): ?ModelCaller
    {
        if (!$this->callerBuilt) {
            $this->caller = ($this->callerFactory)();
            $this->callerBuilt = true;
        }

        return $this->caller;
    }

    /** @return list<string> */
    public function enabledTypes(): array
    {
        $types = apply_filters('meta_writer_post_types', $this->settings->list('post_types'));
        $types = is_array($types) ? array_values(array_filter($types, 'is_string')) : [];

        return array_values(array_diff($types, Settings::FORBIDDEN_TYPES));
    }

    /** @return list<string> */
    public function enabledStatuses(): array
    {
        $statuses = apply_filters('meta_writer_post_statuses', $this->settings->list('post_statuses'));
        $statuses = is_array($statuses) ? array_values(array_filter($statuses, 'is_string')) : [];

        return array_values(array_diff($statuses, Settings::FORBIDDEN_STATUSES));
    }

    /** Case 1 and 2: type, status, no password, never a revision or autosave. */
    public function isCandidate(\WP_Post $post): bool
    {
        if ($post->post_type === 'revision' || wp_is_post_revision($post) !== false || wp_is_post_autosave($post) !== false) {
            return false;
        }
        if ((string) $post->post_password !== '') {
            return false;
        }

        return in_array($post->post_type, $this->enabledTypes(), true)
            && in_array($post->post_status, $this->enabledStatuses(), true);
    }

    public function isExcluded(\WP_Post $post): bool
    {
        $skip = get_post_meta($post->ID, '_meta_writer_skip', true);
        if ($skip === '1' || $skip === 1 || $skip === true) {
            return true;
        }

        return apply_filters('meta_writer_should_process', true, $post) === false;
    }

    /**
     * @param array{fields?: list<string>, regenerate?: bool, refresh_own?: bool, dry_run?: bool, user_id?: int, from_queue?: bool, allow_queue?: bool} $args
     */
    public function generate(int $postId, array $args = []): Outcome
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return new Outcome(Outcome::SKIPPED, $postId, Reason::NOT_CANDIDATE, message: 'post_missing');
        }
        if (!$this->isCandidate($post)) {
            return new Outcome(Outcome::SKIPPED, $postId, Reason::NOT_CANDIDATE);
        }
        if ($this->isExcluded($post)) {
            return new Outcome(Outcome::SKIPPED, $postId, Reason::EXCLUDED);
        }
        try {
            $adapter = $this->adapters->resolve($this->settings->string('target'));
        } catch (TargetUnavailableException $e) {
            return new Outcome(Outcome::STOPPED, $postId, Reason::TARGET_UNAVAILABLE, message: $e->getMessage());
        }

        $wanted = $this->wantedFields($post->ID, $adapter, $args);
        if ($wanted === []) {
            return new Outcome(Outcome::SKIPPED, $postId, Reason::BOTH_FILLED);
        }
        $regenerate = !empty($args['regenerate']);
        $pending = $this->store->latestForPostWithStatus($post->ID, Status::PENDING);
        if ($pending !== null && !$regenerate) {
            return new Outcome(Outcome::SKIPPED, $postId, Reason::PENDING_EXISTS, $wanted);
        }
        $latest = $this->store->latestForPost($post->ID);
        if ($latest !== null && $latest->status === Status::REJECTED && !$regenerate) {
            return new Outcome(Outcome::SKIPPED, $postId, Reason::REJECTED_BEFORE, $wanted);
        }

        $input = Input::fromPost($post, $this->settings);
        if ($input->words < max(1, $this->settings->int('min_words'))) {
            return new Outcome(Outcome::SKIPPED, $postId, Reason::TOO_LITTLE_CONTENT, $wanted, $input->length());
        }

        if (!empty($args['dry_run'])) {
            return new Outcome(Outcome::PLANNED, $postId, '', $wanted, $input->length(), 1);
        }

        if ($this->kill->isOn()) {
            return new Outcome(Outcome::STOPPED, $postId, Reason::DISABLED, $wanted);
        }
        $caller = $this->caller();
        if ($caller === null) {
            return new Outcome(Outcome::STOPPED, $postId, Reason::DISABLED, $wanted, message: 'no_caller');
        }

        if (empty($args['from_queue']) && ($args['allow_queue'] ?? true) && $this->settings->bool('use_queue') && $this->queue->active()) {
            if ($this->cap->remaining() <= 0) {
                return new Outcome(Outcome::STOPPED, $postId, Reason::CAPPED, $wanted);
            }
            $payload = ['fields' => $wanted, 'regenerate' => $regenerate, 'refresh_own' => !empty($args['refresh_own']), 'user_id' => (int) ($args['user_id'] ?? 0)];
            if ($this->queue->enqueue($post->ID, $input->hash(), $payload)) {
                return new Outcome(Outcome::QUEUED, $postId, '', $wanted, $input->length());
            }
        }

        return $this->callAndStore($post, $adapter, $wanted, $input, $pending, (int) ($args['user_id'] ?? 0));
    }

    /**
     * @param array{fields?: list<string>, refresh_own?: bool} $args
     * @return list<string>
     */
    public function wantedFields(int $postId, TargetAdapter $adapter, array $args): array
    {
        $allowed = Field::parseList($this->settings->list('fields'));
        if (!empty($args['fields'])) {
            $allowed = array_values(array_intersect($allowed, $args['fields']));
        }
        $templateAsEmpty = $this->settings->bool('template_as_empty');
        $wanted = [];
        foreach ($allowed as $field) {
            $state = $this->provenance->classify($postId, $field, $adapter, $templateAsEmpty);
            if ($state === 'empty' || ($state === 'ours' && !empty($args['refresh_own']))) {
                $wanted[] = $field;
            }
        }

        return $wanted;
    }

    /** @param list<string> $wanted */
    private function callAndStore(\WP_Post $post, TargetAdapter $adapter, array $wanted, Input $input, ?Suggestion $pending, int $userId): Outcome
    {
        $limits = $this->validator->limits($post);
        $vars = [
            'title' => $input->title,
            'excerpt' => $input->excerpt,
            'body' => $input->body,
            'language' => $input->language,
        ] + $limits;
        $calls = 0;
        $retryNote = '';
        $fits = [];
        $response = null;
        $route = '';
        $version = Prompt::BUILTIN_VERSION;
        $caller = $this->caller();
        assert($caller !== null);

        foreach ([0, 1] as $attempt) {
            $stop = $this->gate($post->ID, $wanted, $calls);
            if ($stop instanceof Outcome) {
                return $stop;
            }
            $reservation = $this->spend->active() ? $this->lastReservation : null;
            [$messages, $version] = $this->prompt->build($vars, $post, $retryNote);
            $calls++;
            try {
                $response = $caller->call($messages, Prompt::schema());
                if ($reservation !== null) {
                    $this->spend->record($reservation, $response->cost);
                }
            } catch (CreditExhaustedException $e) {
                if ($reservation !== null) {
                    $this->spend->release($reservation);
                }
                $this->storeFailure($post->ID, $wanted, $input, 'CreditExhaustedException', $e->getMessage(), $caller->routeLabel(), $version, $userId, null);
                $this->log->error('Every provider is out of credit; run stopped.', ['event' => 'credit_exhausted', 'post_id' => $post->ID]);

                return new Outcome(Outcome::STOPPED, $post->ID, Reason::CREDIT_EXHAUSTED, $wanted, $input->length(), $calls);
            } catch (CallFailedException $e) {
                if ($reservation !== null) {
                    $this->spend->release($reservation);
                }
                $s = $this->storeFailure($post->ID, $wanted, $input, $e->errorClass(), $e->getMessage(), $caller->routeLabel(), $version, $userId, null);
                $this->log->error('Model call failed.', ['event' => 'call_failed', 'post_id' => $post->ID, 'error_class' => $e->errorClass(), 'message' => $e->getMessage()]);

                return new Outcome(Outcome::FAILED, $post->ID, $e->errorClass(), $wanted, $input->length(), $calls, $s);
            }
            $route = $response->route !== '' ? $response->route : $caller->routeLabel();

            [$fits, $failure] = $this->validateOutput($response->data, $wanted, $post, $input);
            if ($failure === null) {
                break;
            }
            if ($attempt === 0) {
                $retryNote = sprintf(
                    'Your previous answer could not be used: in the %s, %s. Write both values again, following every rule, as the same JSON object.',
                    $failure[0],
                    Validator::describe($failure[1]),
                );
                continue;
            }
            $raw = $this->settings->bool('keep_raw') ? mb_substr($response->raw ?? (string) wp_json_encode($response->data, JSON_UNESCAPED_UNICODE), 0, self::RAW_KEEP) : null;
            $s = $this->storeFailure($post->ID, $wanted, $input, 'invalid_output', $failure[0] . ': ' . $failure[1], $route, $version, $userId, $raw);
            $this->log->warning('Model output failed validation twice.', ['event' => 'invalid_output', 'post_id' => $post->ID, 'field' => $failure[0], 'reason' => $failure[1]]);

            return new Outcome(Outcome::FAILED, $post->ID, 'invalid_output', $wanted, $input->length(), $calls, $s);
        }

        $flags = [];
        if ($calls > 1) {
            $flags[] = Flag::RETRIED;
        }
        $title = isset($fits[Field::TITLE]) ? $fits[Field::TITLE]->text : '';
        $description = isset($fits[Field::DESCRIPTION]) ? $fits[Field::DESCRIPTION]->text : '';
        foreach ($fits as $fit) {
            if ($fit->trimmed && !in_array(Flag::TRIMMED, $flags, true)) {
                $flags[] = Flag::TRIMMED;
            }
        }
        $descriptionHash = $description === '' ? '' : self::descriptionHash($description);
        if ($descriptionHash !== '' && $this->store->descriptionHashExists($descriptionHash, $post->ID)) {
            $flags[] = Flag::DUPLICATE;
        }
        if (DetailCheck::unsupported($title . "\n" . $description, $input->haystack()) !== []) {
            $flags[] = Flag::UNSUPPORTED_DETAIL;
        }

        $row = [
            'post_id' => $post->ID,
            'fields' => implode(',', $wanted),
            'title' => $title,
            'description' => $description,
            'title_len' => isset($fits[Field::TITLE]) ? $fits[Field::TITLE]->length : 0,
            'description_len' => isset($fits[Field::DESCRIPTION]) ? $fits[Field::DESCRIPTION]->length : 0,
            'flags' => implode(',', $flags),
            'status' => Status::PENDING,
            'input_hash' => $input->hash(),
            'route' => mb_substr($route, 0, 190),
            'prompt_version' => mb_substr($version, 0, 64),
            'description_hash' => $descriptionHash,
            'created_at' => (int) $this->clock->now(),
            'created_by' => $userId,
        ];
        try {
            $id = $this->store->insert($row);
        } catch (StorageException $e) {
            // Case 75: the call is already counted; log and carry on.
            $this->log->error('Could not store a suggestion.', ['event' => 'store_failed', 'post_id' => $post->ID, 'message' => $e->getMessage()]);

            return new Outcome(Outcome::FAILED, $post->ID, 'store_failed', $wanted, $input->length(), $calls);
        }
        if ($pending !== null) {
            $this->store->transition($pending->id, [Status::PENDING], Status::SUPERSEDED, ['error_message' => 'replaced by #' . $id]);
        }
        $suggestion = $this->store->find($id);
        if ($suggestion !== null) {
            do_action('meta_writer_suggestion_created', $suggestion);
        }

        return new Outcome(Outcome::GENERATED, $post->ID, '', $wanted, $input->length(), $calls, $suggestion);
    }

    private ?string $lastReservation = null;

    /**
     * Kill switch, cap headroom, spend meter, then the atomic cap take.
     *
     * @param list<string> $wanted
     */
    private function gate(int $postId, array $wanted, int $calls): ?Outcome
    {
        $this->lastReservation = null;
        if ($this->kill->isOn()) {
            return new Outcome(Outcome::STOPPED, $postId, Reason::DISABLED, $wanted, calls: $calls);
        }
        if ($this->cap->remaining() <= 0) {
            do_action('meta_writer_cap_reached', $this->cap->cap(), $this->cap->day());

            return new Outcome(Outcome::STOPPED, $postId, Reason::CAPPED, $wanted, calls: $calls);
        }
        if ($this->spend->active()) {
            $answer = $this->spend->preSpend($postId);
            if (!$answer['allowed']) {
                if ($answer['reservation'] !== '') {
                    $this->spend->release($answer['reservation']);
                }
                $this->log->warning('Spend meter denied the call.', ['event' => 'spend_denied', 'post_id' => $postId, 'reason' => $answer['reason']]);

                return new Outcome(Outcome::STOPPED, $postId, Reason::SPEND_DENIED, $wanted, calls: $calls, message: $answer['reason']);
            }
            $this->lastReservation = $answer['reservation'];
        }
        if (!$this->cap->take()) {
            if ($this->lastReservation !== null) {
                $this->spend->release($this->lastReservation);
                $this->lastReservation = null;
            }

            return new Outcome(Outcome::STOPPED, $postId, Reason::CAPPED, $wanted, calls: $calls);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $wanted
     * @return array{0: array<string, FitResult>, 1: array{0: string, 1: string}|null}
     */
    private function validateOutput(array $data, array $wanted, \WP_Post $post, Input $input): array
    {
        $fits = [];
        $title = is_string($data['title'] ?? null) ? $data['title'] : '';
        $description = is_string($data['description'] ?? null) ? $data['description'] : '';
        foreach ($wanted as $field) {
            $text = $field === Field::TITLE ? $title : $description;
            $fit = $this->validator->check($field, $text, $post, $input->language, $field === Field::DESCRIPTION ? $title : '');
            if (!$fit->ok) {
                return [$fits, [$field, (string) $fit->reason]];
            }
            $fits[$field] = $fit;
        }

        return [$fits, null];
    }

    /** @param list<string> $wanted */
    private function storeFailure(int $postId, array $wanted, Input $input, string $class, string $message, string $route, string $version, int $userId, ?string $raw): ?Suggestion
    {
        try {
            $id = $this->store->insert([
                'post_id' => $postId,
                'fields' => implode(',', $wanted),
                'title' => '',
                'description' => '',
                'status' => Status::FAILED,
                'input_hash' => $input->hash(),
                'route' => mb_substr($route, 0, 190),
                'prompt_version' => mb_substr($version, 0, 64),
                'error_class' => mb_substr($class, 0, 100),
                'error_message' => $this->log->redact($message),
                'raw' => $raw,
                'created_at' => (int) $this->clock->now(),
                'created_by' => $userId,
            ]);

            return $this->store->find($id);
        } catch (StorageException $e) {
            $this->log->error('Could not store a failed suggestion.', ['event' => 'store_failed', 'post_id' => $postId]);

            return null;
        }
    }

    public static function descriptionHash(string $description): string
    {
        return hash('sha256', mb_strtolower(trim($description), 'UTF-8'));
    }
}
