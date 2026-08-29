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
    /**
     * DECLARE the ledger resource + replay op into the registries.
     *
     * ⚠️ **Separate from {@see register()} since registry-kernel 70, and they used to be one act.**
     * Declaration is a fact about this package — it declares one resource and one op whether or not
     * anything routes them. Mounting is a fact about the HOST, and specifically about *where* the host
     * calls from. Fusing them is what made a dead route-macro probe silently un-register the resource
     * at all twelve installers.
     *
     * Scanned rather than named — `src/Data` holds the `#[ParticleResource]`, `src/Ops` the replay
     * `#[ParticleOp]`. Idempotent by key, so declaring twice declares the same two.
     */
    public static function declare(): void
    {
        if (! class_exists(ParticleOperationRegistry::class)) {
            return; // beam particle infra genuinely absent (a headless install).
        }

        app(AttributedParticleDiscovery::class)->discover(paths: [
            __DIR__.'/Data',
            __DIR__.'/Ops',
        ]);
    }

    /**
     * MOUNT the declared surface onto HTTP. **Call this from the route file whose group you want.**
     *
     * ⚠️ **Defaults to NO middleware and NO prefix, and that is the whole design (registry-kernel 71).**
     * Measured: Laravel MERGES a nested group's middleware into its parent's rather than replacing it.
     * So a package that names its own stack does not choose between the host's and its own — it
     * APPENDS to the host's. The former `['web','auth']` default, called from inside a tenant route
     * group, produced `api, TenancyInit, auth:sanctum, web, auth`: a session-starting `web` on an API
     * route and the session guard running beside `auth:sanctum`.
     *
     * Declaring nothing makes central-vs-tenant fall out of WHERE the host calls this, which is the one
     * place that actually knows. `Route::middleware([])->prefix('')->group(…)` is inert — measured to
     * inherit the ambient stack exactly, with no stray path segment.
     *
     * The corollary is that this must NOT be called from this package's own provider: a provider boots
     * outside every route group, so self-calling would mount the ledger at the application root with no
     * middleware at all. The provider calls {@see declare()} and nothing else.
     */
    public static function register(array $opts = []): void
    {
        self::declare();

        if (! class_exists(ParticleOperationRegistry::class)) {
            return; // beam particle infra genuinely absent — declared nothing, so mount nothing.
        }

        $groupPrefix = $opts['group_prefix'] ?? config('beam.notifications.resources.group_prefix', '');
        $middleware = $opts['middleware'] ?? config('beam.notifications.resources.middleware', []);
        // A fact about the delegated model, not about the host: `rushing/laravel-notification-status`'
        // `NotificationStatus` uses `HasUuids` and its migration declares `uuid('id')->primary()`.
        $idConstraint = $opts['idConstraint'] ?? 'uuid';

        Route::middleware($middleware)->prefix($groupPrefix)->group(function () use ($idConstraint) {
            Particle::mount('notification-statuses', 'notification-statuses')
                ->idConstraint($idConstraint)
                ->only(['index', 'show']);

            // `Particle::ops()` both DISCOVERS (registers) the annotated op class and mounts it.
            Particle::ops('notification-statuses', 'notification-statuses', [ReplayNotificationStatusOp::class]);
        });
    }
}
