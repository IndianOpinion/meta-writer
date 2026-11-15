<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Cli;

use IndianOpinion\MetaWriter\Adapter\AdapterRegistry;
use IndianOpinion\MetaWriter\Container;
use IndianOpinion\MetaWriter\Exception\MetaWriterException;
use IndianOpinion\MetaWriter\Exception\TargetUnavailableException;
use IndianOpinion\MetaWriter\Field;
use IndianOpinion\MetaWriter\Flag;
use IndianOpinion\MetaWriter\Reason;
use IndianOpinion\MetaWriter\RunReport;
use IndianOpinion\MetaWriter\Runner;
use IndianOpinion\MetaWriter\Status;

/**
 * Drafts SEO titles and meta descriptions for review.
 *
 * Exit codes: 0 success, 1 error, 2 stopped by the cap, the kill switch
 * or a spend deny.
 */
final class Command
{
    public function __construct(private readonly Container $c)
    {
    }

    /**
     * Generate suggestions. A dry run unless --run is given.
     *
     * ## OPTIONS
     *
     * [<id>...]
     * : Post IDs. Without IDs, every candidate is walked.
     *
     * [--post-type=<types>]
     * : Comma-separated post types, narrowing the enabled list.
     *
     * [--status=<statuses>]
     * : Comma-separated statuses, narrowing the enabled list.
     *
     * [--fields=<fields>]
     * : title, description or both (default).
     *
     * [--limit=<n>]
     * : Stop after this many posts reach generation.
     *
     * [--from-id=<cursor>]
     * : Resume from the cursor a previous run printed.
     *
     * [--order=<order>]
     * : oldest (ascending ID, default) or newest.
     *
     * [--time=<seconds>]
     * : Stop after this many seconds. Unlimited by default.
     *
     * [--regenerate]
     * : Replace a pending suggestion and ignore an earlier rejection.
     *
     * [--refresh-own]
     * : Also suggest for fields that hold meta-writer's own value.
     *
     * [--run]
     * : Spend model calls. Without it nothing is called or written.
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function generate(array $args, array $assoc): void
    {
        $run = isset($assoc['run']);
        $format = $assoc['format'] ?? 'table';
        try {
            $fields = Field::parseList($assoc['fields'] ?? null);
        } catch (MetaWriterException $e) {
            \WP_CLI::error($e->getMessage());

            return;
        }
        $runArgs = [
            'fields' => $fields,
            'limit' => isset($assoc['limit']) ? max(0, (int) $assoc['limit']) : 0,
            'from' => $assoc['from-id'] ?? null,
            'order' => ($assoc['order'] ?? 'oldest') === 'newest' ? 'newest' : 'oldest',
            'time' => isset($assoc['time']) ? (float) $assoc['time'] : 0.0,
            'regenerate' => isset($assoc['regenerate']),
            'refresh_own' => isset($assoc['refresh-own']),
            'dry_run' => !$run,
            'user_id' => get_current_user_id(),
            'post_types' => isset($assoc['post-type']) ? array_map('trim', explode(',', $assoc['post-type'])) : [],
            'statuses' => isset($assoc['status']) ? array_map('trim', explode(',', $assoc['status'])) : [],
        ];
        if ($args !== []) {
            $runArgs['ids'] = array_values(array_map('intval', $args));
        }
        $rows = [];
        $report = $this->c->runner->run($runArgs, static function (array $row) use (&$rows, $format): void {
            if ($format === 'table') {
                \WP_CLI::line(sprintf('%d  %-9s %-20s %-18s %6d  %s', $row['id'], $row['outcome'], $row['fields'], $row['reason'], $row['input_length'], $row['title']));
            } else {
                $rows[] = $row;
            }
        });
        $this->printReport($report, $rows, $format);
        if ($report->blocked()) {
            \WP_CLI::halt(2);
        }
    }

    /**
     * @param list<array<string, int|string>> $rows
     */
    private function printReport(RunReport $report, array $rows, string $format): void
    {
        if ($format === 'json') {
            \WP_CLI::line((string) wp_json_encode($report->toArray() + ['rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }
        if ($format === 'csv' && $rows !== []) {
            \WP_CLI\Utils\format_items('csv', $rows, array_keys($rows[0]));
        }
        \WP_CLI::line(sprintf('%s: considered %d, generated %d, failed %d, queued %d, model calls %d%s.', $report->dryRun ? 'Dry run' : 'Run', $report->considered, $report->generated, $report->failed, $report->queued, $report->calls, $report->dryRun ? sprintf(' (would make; %d fit in today\'s cap)', $report->fitsToday) : ''));
        foreach ($report->skipped as $reason => $count) {
            \WP_CLI::line(sprintf('  skipped %s: %d', $reason, $count));
        }
        \WP_CLI::line(sprintf('Stop: %s. Resume cursor: %s.', $report->stopReason, $report->nextCursor ?? 'none (finished)'));
        if ($report->dryRun) {
            \WP_CLI::line('Nothing was called or written. Add --run to spend model calls.');
        }
    }

    /**
     * List suggestions.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : pending, accepted, rejected, stale, superseded or failed.
     *
     * [--flag=<flag>]
     * : trimmed, duplicate, retried or unsupported_detail.
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function list(array $args, array $assoc): void
    {
        $status = $assoc['status'] ?? '';
        if ($status !== '' && !in_array($status, Status::ALL, true)) {
            \WP_CLI::error('Unknown status.');
        }
        $flag = $assoc['flag'] ?? '';
        if ($flag !== '' && !in_array($flag, Flag::ALL, true)) {
            \WP_CLI::error('Unknown flag.');
        }
        $items = [];
        $page = 1;
        do {
            $result = $this->c->suggestions->query(['status' => $status, 'flag' => $flag, 'page' => $page, 'per_page' => 200]);
            foreach ($result['items'] as $s) {
                $items[] = ['id' => $s->id, 'post_id' => $s->postId, 'status' => $s->status, 'fields' => implode(',', $s->fields), 'title' => $s->title, 'description' => $s->description, 'flags' => implode(',', $s->flags), 'error' => $s->error];
            }
            $page++;
        } while (count($items) < $result['total'] && $result['items'] !== []);
        \WP_CLI\Utils\format_items($assoc['format'] ?? 'table', $items, ['id', 'post_id', 'status', 'fields', 'title', 'description', 'flags', 'error']);
    }

    /**
     * Show one suggestion with lengths, flags and the current target values.
     *
     * ## OPTIONS
     *
     * <id>
     * : Suggestion ID.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function show(array $args, array $assoc): void
    {
        $s = $this->c->suggestions->find((int) ($args[0] ?? 0));
        if ($s === null) {
            \WP_CLI::error('No such suggestion.');

            return;
        }
        foreach ($s->toArray() as $k => $v) {
            \WP_CLI::line(sprintf('%-18s %s', $k, is_array($v) ? implode(',', $v) : (string) $v));
        }
        try {
            $adapter = $this->c->adapters->resolve($this->c->settings->string('target'));
            foreach (Field::ALL as $field) {
                \WP_CLI::line(sprintf('%-18s %s', 'current ' . $field, $adapter->read($s->postId, $field)));
            }
        } catch (TargetUnavailableException $e) {
            \WP_CLI::warning($e->getMessage());
        }
    }

    /**
     * Accept suggestions. Without --yes, prints what would be written.
     * Needs the global --user flag.
     *
     * ## OPTIONS
     *
     * [<id>...]
     * : Suggestion IDs.
     *
     * [--all-pending]
     * : Every pending suggestion (stale ones are refused in bulk).
     *
     * [--confirm-stale]
     * : Accept a stale suggestion; single ID only.
     *
     * [--yes]
     * : Write.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function accept(array $args, array $assoc): void
    {
        $user = get_current_user_id();
        if ($user <= 0) {
            \WP_CLI::error('Run accept as a user: add --user=<login>.');

            return;
        }
        $ids = array_values(array_map('intval', $args));
        if (isset($assoc['all-pending'])) {
            $ids = [];
            do {
                $result = $this->c->suggestions->query(['status' => Status::PENDING, 'page' => 1 + intdiv(count($ids), 200), 'per_page' => 200]);
                foreach ($result['items'] as $s) {
                    $ids[] = $s->id;
                }
            } while (count($ids) < $result['total'] && $result['items'] !== []);
        }
        if ($ids === []) {
            \WP_CLI::error('Give suggestion IDs or --all-pending.');

            return;
        }
        $bulk = count($ids) > 1 || isset($assoc['all-pending']);
        if (!isset($assoc['yes'])) {
            foreach ($ids as $id) {
                $s = $this->c->suggestions->find($id);
                if ($s === null) {
                    continue;
                }
                foreach ($s->fields as $field) {
                    \WP_CLI::line(sprintf('would write #%d post %d %s: %s', $s->id, $s->postId, $field, $s->text($field)));
                }
            }
            \WP_CLI::line(sprintf('%d suggestion(s). Nothing written. Add --yes to write.', count($ids)));

            return;
        }
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $r = $this->c->reviewer->accept($id, [], ['confirm_stale' => isset($assoc['confirm-stale']) && !$bulk, 'bulk' => $bulk], $user);
                \WP_CLI::line(sprintf('#%d written: %s; superseded: %s', $id, implode(',', $r->written) ?: '-', implode(',', $r->superseded) ?: '-'));
            } catch (MetaWriterException $e) {
                $failed++;
                \WP_CLI::warning(sprintf('#%d: %s', $id, $e->getMessage()));
            }
        }
        if ($failed > 0) {
            \WP_CLI::halt(1);
        }
    }

    /**
     * Reject suggestions.
     *
     * ## OPTIONS
     *
     * <id>...
     * : Suggestion IDs.
     *
     * [--yes]
     * : Reject without asking.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function reject(array $args, array $assoc): void
    {
        $user = get_current_user_id();
        if ($user <= 0) {
            \WP_CLI::error('Run reject as a user: add --user=<login>.');

            return;
        }
        \WP_CLI::confirm(sprintf('Reject %d suggestion(s)?', count($args)), $assoc);
        foreach ($args as $id) {
            try {
                $this->c->reviewer->reject((int) $id, $user);
                \WP_CLI::line(sprintf('#%d rejected.', (int) $id));
            } catch (MetaWriterException $e) {
                \WP_CLI::warning(sprintf('#%d: %s', (int) $id, $e->getMessage()));
            }
        }
    }

    /**
     * Revert a field meta-writer wrote, while it still holds what was written.
     *
     * ## OPTIONS
     *
     * <post-id>
     * : Post ID.
     *
     * --field=<field>
     * : title or description.
     *
     * [--yes]
     * : Revert without asking.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function revert(array $args, array $assoc): void
    {
        $user = get_current_user_id();
        if ($user <= 0) {
            \WP_CLI::error('Run revert as a user: add --user=<login>.');

            return;
        }
        \WP_CLI::confirm('Revert this field?', $assoc);
        try {
            $this->c->reviewer->revert((int) ($args[0] ?? 0), (string) ($assoc['field'] ?? ''), $user);
            \WP_CLI::success('Reverted.');
        } catch (MetaWriterException $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Target, detected SEO plugins, kill switch, cap, counts and last run.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        try {
            $target = $this->c->adapters->resolve($this->c->settings->string('target'))->id();
        } catch (TargetUnavailableException $e) {
            $target = 'unavailable (' . $e->getMessage() . ')';
        }
        $counts = $this->c->suggestions->countByStatus();
        $last = get_option(Runner::LAST_RUN_OPTION);
        $lines = [
            'target' => $target,
            'detected' => implode(',', array_map(static fn ($a): string => $a->id(), $this->c->adapters->detected())) ?: 'none',
            'kill_switch' => $this->c->kill->isOn() ? 'on (' . $this->c->kill->source() . ')' : 'off',
            'generation' => $this->c->canGenerate() ? 'available' : 'unavailable (library missing or model not configured)',
            'cap' => sprintf('%d used, %d left of %d (day %s)', $this->c->cap->used(), $this->c->cap->remaining(), $this->c->cap->cap(), $this->c->cap->day()),
        ];
        foreach (Status::ALL as $st) {
            $lines[$st] = (string) ($counts[$st] ?? 0);
        }
        $lines['last_run'] = is_array($last) ? (string) wp_json_encode($last) : 'none';
        foreach ($lines as $k => $v) {
            \WP_CLI::line(sprintf('%-12s %s', $k, $v));
        }
    }

    /**
     * Turn the kill switch on or off.
     *
     * ## OPTIONS
     *
     * <state>
     * : on or off.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function kill(array $args, array $assoc): void
    {
        $state = $args[0] ?? '';
        if (!in_array($state, ['on', 'off'], true)) {
            \WP_CLI::error('Use: wp meta-writer kill on|off');

            return;
        }
        $this->c->kill->set($state === 'on', 'cli:' . get_current_user_id());
        $this->c->log->info('Kill switch turned ' . $state . ' from WP-CLI.', ['event' => 'kill']);
        if ($state === 'off' && $this->c->kill->constantOn()) {
            \WP_CLI::warning('META_WRITER_DISABLED is set in code; generation stays off.');

            return;
        }
        \WP_CLI::success('Kill switch ' . $state . '.');
    }

    /**
     * Copy fallback values into another adapter's empty fields.
     *
     * ## OPTIONS
     *
     * --to=<adapter>
     * : yoast, rankmath, seopress or aioseo.
     *
     * [--yes]
     * : Write. Without it, print the plan.
     *
     * @subcommand migrate-target
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function migrateTarget(array $args, array $assoc): void
    {
        $to = $this->c->adapters->get((string) ($assoc['to'] ?? ''));
        $from = $this->c->adapters->get(AdapterRegistry::FALLBACK);
        if ($to === null || $from === null || $to->id() === AdapterRegistry::FALLBACK) {
            \WP_CLI::error('Unknown --to adapter.');

            return;
        }
        if (!$to->isAvailable()) {
            \WP_CLI::error($to->label() . ' is not active.');

            return;
        }
        global $wpdb;
        $ids = [];
        if ($wpdb instanceof \wpdb) {
            $col = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND meta_value <> '' ORDER BY post_id", '_meta_writer_title', '_meta_writer_description'));
            $ids = array_values(array_map('intval', is_array($col) ? $col : []));
        }
        $plan = $this->c->reviewer->migrate($from, $to, $ids, isset($assoc['yes']), get_current_user_id());
        foreach ($plan as $p) {
            \WP_CLI::line(sprintf('post %d %s: %s', $p['post_id'], $p['field'], $p['action']));
        }
        \WP_CLI::line(isset($assoc['yes']) ? 'Done.' : 'Nothing written. Add --yes to copy.');
    }

    /**
     * Remove old rejected, failed, superseded and stale suggestions.
     *
     * ## OPTIONS
     *
     * [--older-than=<days>]
     * : Default: the retention setting.
     *
     * [--yes]
     * : Delete. Without it, print what would be removed.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function purge(array $args, array $assoc): void
    {
        $days = isset($assoc['older-than']) ? max(1, (int) $assoc['older-than']) : max(1, $this->c->settings->int('retention_days'));
        $statuses = [Status::REJECTED, Status::FAILED, Status::SUPERSEDED, Status::STALE];
        if (!isset($assoc['yes'])) {
            \WP_CLI::line(sprintf('Would remove %s suggestions older than %d days. Accepted rows are kept. Add --yes.', implode(', ', $statuses), $days));

            return;
        }
        $n = $this->c->suggestions->purge((int) $this->c->clock->now() - $days * 86400, $statuses);
        \WP_CLI::success(sprintf('Removed %d suggestion(s).', $n));
    }

    /** Exit code for a report, for scripts: 2 when blocked. */
    public static function exitCode(RunReport $report): int
    {
        return in_array($report->stopReason, Reason::BLOCKING, true) ? 2 : 0;
    }
}
