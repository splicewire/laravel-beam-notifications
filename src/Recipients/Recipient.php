<?php

namespace Splicewire\Beam\Notifications\Recipients;

/**
 * One resolved recipient. Two shapes (spec §2), by construction:
 *
 *  - an ON-DEMAND address (from the `to:` key) — mail-only, no persistent Notifiable, so
 *    the `database` inbox channel is unreachable and pkg 25 stores `notifiable = null`.
 *    Dispatched via `Notification::route('mail', $address)`.
 *  - a NOTIFIABLE model (from `to_roles:` / `to_teams:`, resolved by beam-accounts) — full
 *    channels including the `database` inbox. Dispatched via `Notification::send($model)`.
 *
 * The channel constraint is structural: only the model shape can carry `database`.
 */
class Recipient
{
    protected function __construct(
        public ?string $address,
        public ?object $notifiable,
    ) {}

    /**
     * An on-demand mail address (literal or payload-interpolated). Mail-only.
     */
    public static function address(string $address): self
    {
        return new self(address: $address, notifiable: null);
    }

    /**
     * A persistent Notifiable model (a role/team member). Full channels.
     */
    public static function notifiable(object $notifiable): self
    {
        return new self(address: null, notifiable: $notifiable);
    }

    public function isAddress(): bool
    {
        return $this->address !== null;
    }

    public function isNotifiable(): bool
    {
        return $this->notifiable !== null;
    }
}
