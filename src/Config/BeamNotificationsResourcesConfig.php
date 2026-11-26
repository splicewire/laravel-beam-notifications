<?php

namespace Splicewire\Beam\Notifications\Config;

use Schemastud\DataSchemas\Attributes\ArrayItems;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;

/**
 * `beam.notifications /resources` (nested in {@see BeamNotificationsSection}) — the route-group prefix and
 * middleware the mounted delivery-ledger resource and replay operation inherit when the host registers them.
 */
class BeamNotificationsResourcesConfig extends Data
{
    public function __construct(
        #[Title('Group prefix'), Description('Route-group prefix for the mounted delivery-ledger resource and replay operation. Empty inherits the prefix of the host route group that calls `Resources::register()`; set it to mount the surface under a fixed path.'), ConfigKey]
        public string $group_prefix,
        #[Title('Middleware'), Description('Middleware applied to the mounted resource routes. Empty inherits the middleware of the host route group that mounts them; list middleware names to add them.'), ConfigKey, ArrayItems('string')]
        public array $middleware,
    ) {
    }
}
