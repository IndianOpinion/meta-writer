<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

/**
 * What a run did or, for a dry run, would do.
 */
final class RunReport
{
    /**
     * @param array<string, int>              $skipped reason => count
     * @param list<array<string, int|string>> $rows    one row per post considered
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly int $considered,
        public readonly array $skipped,
        public readonly int $generated,
        public readonly int $failed,
        public readonly int $calls,
        public readonly string $stopReason,
        public readonly ?string $nextCursor,
        public readonly float $seconds,
        public readonly int $fitsToday = 0,
        public readonly array $rows = [],
        public readonly int $queued = 0,
    ) {
    }

    /** True when the run stopped because nothing more is allowed today. */
    public function blocked(): bool
    {
        return in_array($this->stopReason, Reason::BLOCKING, true);
    }

    /** @return array<string, mixed> */
    public function toArray(bool $withRows = false): array
    {
        $out = [
            'dry_run' => $this->dryRun,
            'considered' => $this->considered,
            'skipped' => $this->skipped,
            'generated' => $this->generated,
            'failed' => $this->failed,
            'queued' => $this->queued,
            'calls' => $this->calls,
            'fits_today' => $this->fitsToday,
            'stop_reason' => $this->stopReason,
            'next_cursor' => $this->nextCursor,
            'seconds' => round($this->seconds, 3),
        ];
        if ($withRows) {
            $out['rows'] = $this->rows;
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $skipped = [];
        foreach ((array) ($data['skipped'] ?? []) as $k => $v) {
            $skipped[(string) $k] = (int) $v;
        }

        return new self(
            (bool) ($data['dry_run'] ?? false),
            (int) ($data['considered'] ?? 0),
            $skipped,
            (int) ($data['generated'] ?? 0),
            (int) ($data['failed'] ?? 0),
            (int) ($data['calls'] ?? 0),
            (string) ($data['stop_reason'] ?? ''),
            isset($data['next_cursor']) ? (string) $data['next_cursor'] : null,
            (float) ($data['seconds'] ?? 0.0),
            (int) ($data['fits_today'] ?? 0),
            [],
            (int) ($data['queued'] ?? 0),
        );
    }
}
