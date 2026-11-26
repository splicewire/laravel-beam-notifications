<?php

namespace Splicewire\Beam\Notifications\Config;

use Schemastud\DataSchemas\Attributes\ArrayItems;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\MapValues;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;

/**
 * The `beam.notifications` Config Section (ADR-0245), the declared shape of `config/beam/notifications.php`.
 *
 * beam-notifications owns the `x-beam-notify` keyword and the submission -> notify wiring. Every key is
 * deploy-only and host-ceilinged (a bare #[ConfigKey] is `ceiling: Host, effect: Deploy`): the package ships
 * the value and nothing below the host may override it until a key is opted in.
 */
#[ConfigRoot(root: 'beam.notifications', title: 'Beam notifications')]
class BeamNotificationsSection extends ConfigSection
{
    public function __construct(
        #[Title('Listen'), Description('Master switch for the `BeamSubmission::created` -> notify listener. Off, a submission is still captured (beam\'s job) but no notification is dispatched.'), ConfigKey]
        public bool $listen,
        #[Title('Default channels'), Description('The generic driver\'s default channel list, used when a schema\'s `x-beam-notify` omits `channels`. `via()` always intersects this with the app\'s registered channel drivers, so naming `central` on a headless beam (no relay provider) is a silent no-op, never a crash.'), ConfigKey, ArrayItems('string')]
        public array $default_channels,
        #[MapName('recipient-kinds'), Title('Recipient kinds'), Description('The recipient kinds the `x-beam-notify` keyword can select with: a map of keyword key -> class-string implementing `Contracts\RecipientKind`. This package registers `to` (literal and payload-ref addresses); splicewire/laravel-beam-accounts appends `to_roles` / `to_teams` when installed, and a host may add its own. An unregistered kind is simply an absent entry, so no package probes whether another is installed to decide whether a key resolves.'), ConfigKey, MapValues('string')]
        public array $recipientKinds,
        #[Title('Resources'), Description('The package declares the delivery-ledger resource and replay operation at boot; to expose them over HTTP the host calls `Resources::register()` from its chosen route group. The empty defaults inherit that group\'s prefix and middleware. Reads stay policy-scoped and replay requires the declared `update` ability.'), ConfigKey]
        public BeamNotificationsResourcesConfig $resources,
        #[Title('API root'), Description('The package\'s own API root (ADR-0243). `Routing\NotificationsRoutes::mount()` registers here, inside the host\'s boundary; the `resources.group_prefix` mount stays beside it until ticket 16.'), ConfigKey]
        public string $api_root,
    ) {
    }
}
