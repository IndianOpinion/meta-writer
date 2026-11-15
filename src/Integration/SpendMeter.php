<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Integration;

/**
 * Optional spend-meter integration (case 57): one pre-spend check, then
 * exactly one record or release per reservation.
 *
 * Protocol used: the filter spend_meter_pre_spend receives null and a
 * request array, and returns an array with "allowed" (bool), "reason"
 * (string) and "reservation" (string). Anything else is a deny. The
 * actions spend_meter_record and spend_meter_release close a reservation.
 */
final class SpendMeter
{
    public const PURPOSE = 'meta-writer.generate';

    /** Reason codes that may accompany an allow. Anything else denies. */
    public const ALLOW_REASONS = ['', 'ok', 'allowed', 'within_budget', 'unmetered'];

    public function active(): bool
    {
        return has_filter('spend_meter_pre_spend') !== false;
    }

    /**
     * @return array{allowed: bool, reason: string, reservation: string}
     */
    public function preSpend(int $postId): array
    {
        $answer = apply_filters('spend_meter_pre_spend', null, ['purpose' => self::PURPOSE, 'units' => 1, 'context' => ['post_id' => $postId]]);
        if (!is_array($answer)) {
            return ['allowed' => false, 'reason' => 'no_answer', 'reservation' => ''];
        }
        $reason = isset($answer['reason']) && is_scalar($answer['reason']) ? (string) $answer['reason'] : '';
        $reservation = isset($answer['reservation']) && is_scalar($answer['reservation']) ? (string) $answer['reservation'] : '';
        $allowReasons = apply_filters('meta_writer_spend_allow_reasons', self::ALLOW_REASONS);
        $allowReasons = is_array($allowReasons) ? $allowReasons : self::ALLOW_REASONS;
        $allowed = ($answer['allowed'] ?? null) === true && in_array($reason, $allowReasons, true);
        if (($answer['allowed'] ?? null) === true && !$allowed) {
            $reason = 'unknown_reason:' . $reason;
        }

        return ['allowed' => $allowed, 'reason' => $reason === '' && !$allowed ? 'denied' : $reason, 'reservation' => $reservation];
    }

    public function record(string $reservation, ?float $cost): void
    {
        do_action('spend_meter_record', $reservation, ['purpose' => self::PURPOSE, 'cost' => $cost]);
    }

    public function release(string $reservation): void
    {
        do_action('spend_meter_release', $reservation, ['purpose' => self::PURPOSE]);
    }
}
