<?php

namespace Splicewire\Beam\Notifications\Routing;

use Illuminate\Routing\Router;
use Splicewire\Beam\Notifications\Resources;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Routing\ApiRoot;

/**
 * The notifications package's routes at its own root (ADR-0243, owner-review-20261112 13d):
 * `beam.notifications.api_root`, default `beam/notifications`. The host calls this inside its authentication and
 * tenant boundary, beside the legacy `Resources::register()` it calls today, which stays until ticket 16. The package
 * adds no middleware here: the host's group is the boundary. Route names start `beam.notifications.`.
 */
final class NotificationsRoutes
{
    /** The notification-statuses read surface and its replay op: what `Resources::register()` mounts today. */
    public static function mount(array $opts = []): void
    {
        Resources::declare();

        if (! class_exists(ParticleOperationRegistry::class)) {
            return; // beam particle infra genuinely absent, as Resources::register().
        }

        $idConstraint = $opts['idConstraint'] ?? 'uuid';

        ApiRoot::mount(app(Router::class), 'beam.notifications.api_root', 'notifications', function (Router $router) use ($idConstraint): void {
            $router->name('beam.notifications.')->group(fn () => Resources::routes($idConstraint));
        });
    }
}
