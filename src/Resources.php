<?php

namespace Splicewire\Beam\Notifications;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Notifications\Data\NotificationStatusData;
use Splicewire\Beam\Notifications\Ops\ReplayNotificationStatusOp;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * Register + mount the delivery-ledger particle surface (beam-facade ticket 75) — the operator view
 * of `rushing/laravel-notification-status`, which this package delegated durability to and therefore
 * owns the surface of (58 Q3).
 *
 * Guarded on beam's particle infra exactly as `laravel-beam-rank`'s `Resources` is, so the package
 * boots (and its standalone suite runs) in a context without the route macros: registration and
 * mounting are one act here, and neither happens where there is nothing to mount into.
 *
 * `index` + `show` only. The ledger is `readOnly` — the one write it accepts is the replay op, which
 * mounts alongside at `POST {prefix}/notification-statuses/{id}/op/replay`.
 */
class Resources
{
    public static function register(array $opts = []): void
    {
        if (! class_exists(ParticleOperationRegistry::class) || ! Route::hasMacro('particleResource')) {
            return; // beam particle infra absent (e.g. a standalone package test env) — nothing to mount.
        }

        if (! ($opts['enabled'] ?? config('beam.notifications.resources.enabled', true))) {
            return;
        }

        $groupPrefix = $opts['group_prefix'] ?? config('beam.notifications.resources.group_prefix', 'resources');
        $middleware = $opts['middleware'] ?? config('beam.notifications.resources.middleware', ['web', 'auth']);

        app(AttributedParticleDiscovery::class)->discover([NotificationStatusData::class]);

        Route::middleware($middleware)->prefix($groupPrefix)->group(function () {
            Particle::mount('notification-statuses', 'notification-statuses')->only(['index', 'show']);

            // `Particle::ops()` both DISCOVERS (registers) the annotated op class and mounts it.
            Particle::ops('notification-statuses', 'notification-statuses', [ReplayNotificationStatusOp::class]);
        });
    }
}
