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

];
