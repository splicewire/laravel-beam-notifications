<?php

namespace Splicewire\Beam\Notifications;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Notifications\Ops\ReplayNotificationStatusOp;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * Register + mount the delivery-ledger particle surface (beam-facade ticket 75) — the operator view
 * of `rushing/laravel-notification-status`, which this package delegated durability to and therefore
 * owns the surface of (58 Q3).
 *
 * Guarded on beam's particle infra — `class_exists()` and nothing more — so the package boots (and its
 * standalone suite runs) where beam is genuinely absent. Registration and mounting are ONE act here,
 * which is why the guard matters more than it does in `laravel-beam-calendars` (whose `declare()`
 * survives its guard independently).
 *
 * ⚠️ **This guard also tested `Route::hasMacro('particleResource')` until registry-kernel ticket 70.**
 * api-surface-coherence 93 deleted that macro on 2026-08-27 — in the very commit that converted the
 * mount calls below (`ea8b094`, three lines apart, same hunk) — so for six days the guard was an
 * unconditional early return and this package's resource was registered at **zero** of its twelve
 * installers. Measured at eight bootable hosts: `routes=0, registered=false` everywhere. The macro
 * bodies now live in `ParticleMounter` behind an injected `Router`, so no router probe is meaningful.
 *
 * There is no `enabled` config. It was `beam.notifications.resources.enabled`, defaulting true, and no
 * host ever set it — because the surface was gated shut from birth, so nothing could have opted out of
 * it. Installing the package is the opt-in (registry-kernel 70 Q2).
 *
 * `index` + `show` only. The ledger is `readOnly` — the one write it accepts is the replay op, which
 * mounts alongside at `POST {prefix}/notification-statuses/{id}/op/replay`.
 */
class Resources
{
    public static function register(array $opts = []): void
    {
        if (! class_exists(ParticleOperationRegistry::class)) {
            return; // beam particle infra genuinely absent (a headless install) — nothing to mount into.
        }

        $groupPrefix = $opts['group_prefix'] ?? config('beam.notifications.resources.group_prefix', 'resources');
        $middleware = $opts['middleware'] ?? config('beam.notifications.resources.middleware', ['web', 'auth']);
        // A fact about the delegated model, not about the host: `rushing/laravel-notification-status`'
        // `NotificationStatus` uses `HasUuids` and its migration declares `uuid('id')->primary()`.
        $idConstraint = $opts['idConstraint'] ?? 'uuid';

        // This package's own declaration roots, scanned rather than named — `src/Data` holds the
        // `#[ParticleResource]`, `src/Ops` the replay `#[ParticleOp]`. Idempotent by key, so the
        // `Particle::ops()` call below still discovers-and-mounts exactly as it did.
        app(AttributedParticleDiscovery::class)->discover(paths: [
            __DIR__.'/Data',
            __DIR__.'/Ops',
        ]);

        Route::middleware($middleware)->prefix($groupPrefix)->group(function () use ($idConstraint) {
            Particle::mount('notification-statuses', 'notification-statuses')
                ->idConstraint($idConstraint)
                ->only(['index', 'show']);

            // `Particle::ops()` both DISCOVERS (registers) the annotated op class and mounts it.
            Particle::ops('notification-statuses', 'notification-statuses', [ReplayNotificationStatusOp::class]);
        });
    }
}
