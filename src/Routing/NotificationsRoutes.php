<?php

namespace Splicewire\Beam\Notifications\Routing;

use Illuminate\Routing\Router;
use Splicewire\Beam\Notifications\Resources;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\ApiRoot;

/**
 * The notifications package's routes at its own root (ADR-0243, owner-review-20261112 13d):
 * `beam.notifications.api_root`, default `beam/notifications`. The host calls this inside its authentication and
 * tenant boundary, beside the legacy `Resources::register()` it calls today, which stays until ticket 16. The package
 * adds no middleware here: the host's group is the boundary. Route names start `beam.notifications.`.
 */
final class NotificationsRoutes
{
    /** The notification-statuses read surface and its replay op (what `Resources::register()` mounts today), and the reader's mark-read operations (attention-surfaces 10). */
    public static function mount(array $opts = []): void
    {
        self::mountAt($opts, reader: true);
    }

    /**
     * The delivery LEDGER only: the notification-statuses read surface and its replay op, without the reader's
     * mark-read operations (attention-surfaces 31). For a host that seats the ledger in a second group (an operator
     * console) beside its tenant `mount()`: the reader's operations must exist once, in the reader's boundary. Mounting
     * the whole package twice registers their route names again, and the LAST registration wins URL generation, so the
     * reader's manifest actions would point at the second group's URLs.
     */
    public static function mountLedger(array $opts = []): void
    {
        self::mountAt($opts, reader: false);
    }

    private static function mountAt(array $opts, bool $reader): void
    {
        if (! class_exists(ParticleOperationRegistry::class)) {
            return; // beam particle infra genuinely absent, as Resources::register().
        }

        if (! app(ParticleResourceRegistry::class)->has('notification-statuses')) {
            Resources::declare();
        }

        $idConstraint = $opts['idConstraint'] ?? 'uuid';

        ApiRoot::mount(app(Router::class), 'beam.notifications.api_root', 'notifications', function (Router $router) use ($idConstraint, $reader): void {
            $router->name('beam.notifications.')->group(function () use ($idConstraint, $reader): void {
                Resources::routes($idConstraint);
                if ($reader) {
                    // attention-surfaces 10: the reader's mark-read operations, at the package root only.
                    Resources::readerRoutes();
                }
            });
        });
    }
}
