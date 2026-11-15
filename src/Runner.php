<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

use IndianOpinion\MetaWriter\Clock\Clock;
use IndianOpinion\MetaWriter\Store\PostSource;

/**
 * Runs generation over a selection (cases 8, 9, 43 to 45).
 */
final class Runner
{
    public const LAST_RUN_OPTION = 'meta_writer_last_run';

    public function __construct(
        private readonly Settings $settings,
        private readonly Generator $generator,
        private readonly PostSource $posts,
        private readonly DailyCap $cap,
        private readonly Log $log,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array{ids?: list<int>, post_types?: list<string>, statuses?: list<string>, fields?: list<string>, limit?: int, from?: string|null, order?: string, time?: float, regenerate?: bool, refresh_own?: bool, dry_run?: bool, user_id?: int, allow_queue?: bool} $args
     * @param callable(array<string, int|string>): void|null $onRow called per post; rows are then not kept in the report
     */
    public function run(array $args, ?callable $onRow = null): RunReport
    {
        $start = $this->clock->now();
        $dry = $args['dry_run'] ?? true;
        $limit = max(0, (int) ($args['limit'] ?? 0));
        $time = (float) ($args['time'] ?? 0);
        $order = ($args['order'] ?? PostSource::ORDER_OLDEST) === PostSource::ORDER_NEWEST ? PostSource::ORDER_NEWEST : PostSource::ORDER_OLDEST;
        $types = $this->generator->enabledTypes();
        if (!empty($args['post_types'])) {
            $types = array_values(array_intersect($types, $args['post_types']));
        }
        $statuses = $this->generator->enabledStatuses();
        if (!empty($args['statuses'])) {
            $statuses = array_values(array_intersect($statuses, $args['statuses']));
        }
        $genArgs = [
            'fields' => $args['fields'] ?? [],
            'regenerate' => !empty($args['regenerate']),
            'refresh_own' => !empty($args['refresh_own']),
            'dry_run' => $dry,
            'user_id' => (int) ($args['user_id'] ?? 0),
            'allow_queue' => $args['allow_queue'] ?? true,
        ];

        $considered = 0;
        $skipped = [];
        $generated = 0;
        $failed = 0;
        $queued = 0;
        $planned = 0;
        $calls = 0;
        $rows = [];
        $cursor = isset($args['from']) && $args['from'] !== '' ? (string) $args['from'] : null;
        $stop = '';
        $batch = max(1, $this->settings->int('batch_size'));
        $explicit = isset($args['ids']) ? array_values(array_unique(array_map('intval', $args['ids']))) : null;
        $explicitIndex = 0;

        while ($stop === '') {
            if ($explicit !== null) {
                $page = [];
                foreach (array_slice($explicit, $explicitIndex, $batch) as $id) {
                    $page[] = ['id' => $id, 'cursor' => (string) $id];
                }
                $explicitIndex += $batch;
            } else {
                $page = $this->posts->next($types, $statuses, $cursor, $batch, $order);
            }
            if ($page === []) {
                $stop = Reason::EMPTY;
                $cursor = null;
                break;
            }
            foreach ($page as $item) {
                if ($time > 0 && ($this->clock->now() - $start) >= $time) {
                    $stop = Reason::TIME;
                    break;
                }
                if ($limit > 0 && ($generated + $failed + $queued + $planned) >= $limit) {
                    $stop = Reason::LIMIT;
                    break;
                }
                $outcome = $this->generator->generate($item['id'], $genArgs);
                $calls += $outcome->calls;
                if ($outcome->kind === Outcome::STOPPED) {
                    $stop = $outcome->reason;
                    break;
                }
                $considered++;
                $cursor = $item['cursor'];
                switch ($outcome->kind) {
                    case Outcome::SKIPPED:
                        $skipped[$outcome->reason] = ($skipped[$outcome->reason] ?? 0) + 1;
                        break;
                    case Outcome::PLANNED:
                        $planned++;
                        break;
                    case Outcome::GENERATED:
                        $generated++;
                        break;
                    case Outcome::FAILED:
                        $failed++;
                        break;
                    case Outcome::QUEUED:
                        $queued++;
                        break;
                }
                $row = [
                    'id' => $item['id'],
                    'title' => mb_substr(self::postTitle($item['id']), 0, 80),
                    'outcome' => $outcome->kind,
                    'fields' => implode(',', $outcome->fields),
                    'reason' => $outcome->reason,
                    'input_length' => $outcome->inputLength,
                    'suggestion' => $outcome->suggestion !== null ? $outcome->suggestion->id : 0,
                ];
                if ($onRow !== null) {
                    $onRow($row);
                } else {
                    $rows[] = $row;
                }
            }
            if ($explicit !== null && $stop === '' && $explicitIndex >= count($explicit)) {
                $stop = Reason::EMPTY;
                $cursor = null;
            }
        }

        $report = new RunReport(
            $dry,
            $considered,
            $skipped,
            $generated,
            $failed,
            $dry ? $planned : $calls,
            $stop,
            $stop === Reason::EMPTY ? null : $cursor,
            $this->clock->now() - $start,
            $dry ? min($planned, $this->cap->remaining()) : 0,
            $rows,
            $queued,
        );
        if (!$dry) {
            update_option(self::LAST_RUN_OPTION, $report->toArray(), false);
            $this->log->info(sprintf('Run finished: %d generated, %d failed, %d calls, stop %s.', $generated, $failed, $calls, $stop), ['event' => 'run_finished', 'stop' => $stop, 'cursor' => (string) $report->nextCursor]);
        }
        do_action('meta_writer_run_finished', $report);

        return $report;
    }

    private static function postTitle(int $id): string
    {
        $post = get_post($id);

        return $post instanceof \WP_Post ? html_entity_decode((string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }
}
