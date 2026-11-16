<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter\Integration;

/**
 * Optional spend-meter integration (case 57), against its public
 * contract version 1.
 *
 * Detection: the constant SPEND_METER_API. Absent, or a lower version,
 * means no meter. A higher version is a contract this release does not
 * know, so every call is denied (fail closed).
 *
 * Per call: apply_filters('spend_meter_pre_spend', null, $intent). A null
 * answer means no meter is listening; meta-writer's own cap and kill switch
 * still apply. Anything but allowed === true with reason "ok" is a deny,
 * including an unknown reason code, a malformed answer and a thrown error.
 * After an allow, exactly one spend_meter_record (the call was made,
 * success or failure) or spend_meter_release (meta-writer decided not to
 * call) follows for the reservation id.
 */
final class SpendMeter
{
    public const CONTRACT = 1;
    public const PURPOSE = 'meta-writer.generate';
    public const CALLER = 'meta-writer';

    /** Reason codes that may accompany an allow. Anything else denies. */
    public const ALLOW_REASONS = ['ok'];

    /** Is a spend meter installed (whether or not it answers)? */
    public function active(): bool
    {
        return defined('SPEND_METER_API');
    }

    /**
     * Split a route label ("provider/model") into the intent's provider
     * slug and model id.
     *
     * @return array{0: string, 1: string}
     */
    public static function providerAndModel(string $route): array
    {
        $parts = explode('/', $route, 2);
        $provider = strtolower(trim($parts[0]));
        $provider = (string) preg_replace('/[^a-z0-9_.-]+/', '-', $provider);
        $provider = substr(ltrim($provider, '_.-'), 0, 64);
        if ($provider === '') {
            $provider = 'custom';
        }
        $model = trim($parts[1] ?? '');
        if ($model === '') {
            $model = $provider;
        }

        return [$provider, mb_substr($model, 0, 128)];
    }

    /**
     * Ask the meter before one call.
     *
     * @return array{allowed: bool, reason: string, reservation_id: string|null}|null null when no meter answers
     */
    public function preSpend(int $postId, string $route, int $estInputTokens, int $maxOutputTokens): ?array
    {
        if (!$this->active()) {
            return null;
        }
        $api = constant('SPEND_METER_API');
        if (!is_int($api)) {
            return self::deny('unsupported_api');
        }
        if ($api < self::CONTRACT) {
            return null;
        }
        if ($api > self::CONTRACT) {
            return self::deny('unsupported_api');
        }
        [$provider, $model] = self::providerAndModel($route);
        try {
            $answer = apply_filters('spend_meter_pre_spend', null, [
                'purpose' => self::PURPOSE,
                'caller' => self::CALLER,
                'provider' => $provider,
                'model' => $model,
                'est_input_tokens' => max(0, $estInputTokens),
                'max_output_tokens' => max(1, $maxOutputTokens),
                'meta' => ['post_id' => $postId],
            ]);
        } catch (\Throwable $e) {
            return self::deny('meter_error');
        }
        if ($answer === null) {
            return null;
        }
        if (!is_array($answer)) {
            return self::deny('malformed_decision');
        }
        $reason = isset($answer['reason']) && is_string($answer['reason']) ? $answer['reason'] : '';
        $reservation = isset($answer['reservation_id']) && is_string($answer['reservation_id']) && $answer['reservation_id'] !== '' ? $answer['reservation_id'] : null;
        if (($answer['allowed'] ?? null) !== true) {
            return self::deny($reason === '' ? 'denied' : $reason);
        }
        // From here on an allow holds a reservation: any deny must give it back.
        $allowReasons = apply_filters('meta_writer_spend_allow_reasons', self::ALLOW_REASONS);
        $allowReasons = is_array($allowReasons) ? $allowReasons : self::ALLOW_REASONS;
        $denyReason = '';
        if (!in_array($reason, $allowReasons, true)) {
            $denyReason = 'unknown_reason:' . $reason;
        } elseif (isset($answer['api']) && $answer['api'] !== self::CONTRACT) {
            $denyReason = 'unsupported_api';
        }
        if ($denyReason !== '') {
            if ($reservation !== null) {
                $this->release($reservation);
            }

            return self::deny($denyReason);
        }

        return ['allowed' => true, 'reason' => $reason, 'reservation_id' => $reservation];
    }

    /**
     * The call was made. Recorded with or without a reservation, billed or
     * not (spend-meter's rule: record every attempt).
     *
     * @param string $status ok, error_billed or error_unbilled
     */
    public function record(?string $reservationId, string $route, string $status, int $attempt, ?int $inputTokens = null, ?int $outputTokens = null, ?float $cost = null, ?int $httpStatus = null, int $postId = 0): void
    {
        [$provider, $model] = self::providerAndModel($route);
        $call = [
            'reservation_id' => $reservationId,
            'purpose' => self::PURPOSE,
            'caller' => self::CALLER,
            'provider' => $provider,
            'model' => $model,
            'status' => $status,
            'attempt' => max(1, $attempt),
            'input_tokens' => max(0, (int) $inputTokens),
            'output_tokens' => max(0, (int) $outputTokens),
            'meta' => ['post_id' => $postId],
        ];
        if ($cost !== null && $cost >= 0) {
            $call['provider_cost'] = (int) round($cost * 1_000_000_000);
        }
        if ($httpStatus !== null) {
            $call['http_status'] = $httpStatus;
        }
        do_action('spend_meter_record', $call);
    }

    /** meta-writer decided not to make the call it reserved for. */
    public function release(string $reservationId): void
    {
        do_action('spend_meter_release', $reservationId);
    }

    /** @return array{allowed: false, reason: string, reservation_id: null} */
    private static function deny(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason, 'reservation_id' => null];
    }
}
