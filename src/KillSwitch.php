<?php

declare(strict_types=1);

namespace IndianOpinion\MetaWriter;

use IndianOpinion\MetaWriter\Store\CounterStore;

/**
 * The kill switch (PRD case 50). Read fresh from the database every time.
 *
 * Off means generation may run. On when any of: the constant
 * META_WRITER_DISABLED is true, the network option is on (multisite), or
 * the site option is on. The option can never override the constant.
 */
final class KillSwitch
{
    public const OPTION = 'meta_writer_kill';
    public const NETWORK_OPTION = 'meta_writer_network_kill';
    public const CONSTANT = 'META_WRITER_DISABLED';

    public function __construct(private readonly CounterStore $store)
    {
    }

    public function constantOn(): bool
    {
        return defined(self::CONSTANT) && (bool) constant(self::CONSTANT);
    }

    public function networkOn(): bool
    {
        return self::truthy($this->store->freshNetworkOption(self::NETWORK_OPTION));
    }

    public function optionOn(): bool
    {
        return self::truthy($this->store->freshOption(self::OPTION));
    }

    public function isOn(): bool
    {
        return $this->constantOn() || $this->networkOn() || $this->optionOn();
    }

    /** "constant", "network", "option" or "" when off. */
    public function source(): string
    {
        if ($this->constantOn()) {
            return 'constant';
        }
        if ($this->networkOn()) {
            return 'network';
        }

        return $this->optionOn() ? 'option' : '';
    }

    public function set(bool $on, string $actor): void
    {
        $old = $this->optionOn();
        update_option(self::OPTION, $on ? '1' : '0', true);
        if ($old !== $on) {
            do_action('meta_writer_kill_switch_changed', $old, $on, $actor);
        }
    }

    private static function truthy(?string $value): bool
    {
        return $value !== null && in_array(strtolower(trim($value)), ['1', 'on', 'yes', 'true'], true);
    }
}
