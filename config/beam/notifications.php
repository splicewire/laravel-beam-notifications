<?php

return [

    /*
    |--------------------------------------------------------------------------
    | beam-notifications — the notify capability
    |--------------------------------------------------------------------------
    | "A beam can notify." This package owns the `x-beam-notify` keyword and the
    | submission -> notify wiring. It is deliberately thin: delivery tracking is
    | rushing/laravel-notification-status (consumed via native events, no coupling),
    | roles/teams resolution is beam-accounts (a soft dep), and the central relay is
    | a satellite-registered custom channel — none of that lives here.
    */

    /*
    | Master switch for the BeamSubmission::created -> notify listener. Off means a
    | submission is captured (beam's job) but no notification is dispatched.
    */
    'listen' => true,

    /*
    | The generic driver's default channel list, used when a schema's `x-beam-notify`
    | omits `channels`. via() always intersects this with the app's registered channel
    | drivers, so listing `central` here on a headless beam (no relay provider) is a
    | silent no-op, never a crash.
    */
    'default_channels' => ['mail'],

    /*
    | The recipient KINDS the `x-beam-notify` keyword can select with — the registry that
    | replaced the rebindable RecipientResolver seam (beam-facade 100, built by 159).
    |
    | A key here is a keyword key verbatim; its value is a class-string implementing
    | Contracts\RecipientKind. This package registers `to` (literal + payload-ref addresses,
    | mail-only). splicewire/laravel-beam-accounts APPENDS `to_roles` / `to_teams` from its own
    | provider when installed — so an unregistered kind is simply an absent entry here, and no
    | package has to probe whether another is installed to decide whether a key resolves.
    |
    | A host may add its own kind. It is reached by any keyword key spelled `to` or `to_*`;
    | `in_*` is reserved for the declared scope-modifier growth path, which contributes nobody.
    */
    'recipient_kinds' => [
        'to' => Splicewire\Beam\Notifications\Recipients\Kinds\AddressRecipientKind::class,
    ],

    /*
    | The delivery-ledger particle surface: a framed, read-only list of
    | rushing/laravel-notification-status rows plus the `replay` operation
    | (`POST {group_prefix}/notification-statuses/{id}/op/replay`). This package delegated
    | durability to that ledger, so it owns the operator view of it (beam-facade 58 Q3/Q4).
    |
    | The op is deny-default: it authorizes `update` against the ledger row, so a host that
    | registers no policy for the model gets a 403 until it does. `enabled => false` mounts
    | nothing at all.
    */
    'resources' => [
        'enabled' => true,
        'group_prefix' => 'resources',
        'middleware' => ['web', 'auth'],
    ],

];
