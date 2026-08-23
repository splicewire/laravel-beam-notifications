<?php

namespace Splicewire\Beam\Notifications;

use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Notifications\Contracts\RecipientResolver;
use Splicewire\Beam\Notifications\Doctor\BeamNotificationsMigrationsAudit;
use Splicewire\Beam\Notifications\Listeners\NotifyOnSubmission;
use Splicewire\Beam\Notifications\Recipients\DefaultRecipientResolver;
use Splicewire\Beam\Notifications\Support\RegistrySchemaResolver;

/**
 * The notify-capability provider. "A beam can notify."
 *
 * packageRegistered(): merge config; bind the ONE rebindable seam to its built-in default —
 *   - RecipientResolver -> DefaultRecipientResolver (address-only `to:`). beam-accounts'
 *     provider REBINDS this to its accounts-aware resolver when installed (soft dep, §2).
 *
 * Schema resolution is NOT a second seam here (beam-facade ticket 40): {@see RegistrySchemaResolver}
 * is a concrete class the listener depends on directly, autowired over beam-core's
 * `SchemaTargetResolver` port. It had an interface, justified by a package that no longer exists,
 * and no host in the estate ever rebound it. A host that needs different policy binds a subclass
 * against the class.
 *
 * packageBooted(): listen on {@see BeamParticlePersisted} (the ONE post-persist signal every beam write
 * path emits — ADR-0150 / beam-write-pipeline ticket 05), gated by config; publish config.
 *
 * REWIRE (ticket 05): the old trigger was `eloquent.created: Splicewire\Beam\Models\BeamSubmission`
 * — a model ADR-0138 retired, so the listener fired on a corpse. It now listens on the generic
 * `BeamParticlePersisted` event, so ANY persisted record (public intake, Frame edit, an adopted CRUD
 * controller, the generation populator) can drive a notification, not just a submission.
 *
 * What this provider does NOT do (by design):
 *   - it registers NO `central` channel and ships NO relay transport. `central` is only a
 *     channel-NAME string a schema may list; the satellite provider registers the real
 *     channel via Notification::extend('central', ...). A headless beam never loads that
 *     provider, so via() (BeamNotification) drops the unregistered `central` — zero relay
 *     code travels with beam (§3).
 *   - it writes NO delivery-tracking code. Durability is rushing/laravel-notification-status
 *     (DESIGN §7 L4 decision): it subscribes to Laravel's native notification events globally, so
 *     the moment a BeamNotification is sent it is recorded automatically — no outbox, no coupling
 *     here. The dissolved submissions package's bespoke outbox is NOT folded in; it is deleted.
 *     What this package DOES own is the operator VIEW of that ledger (ticket 75) — the framed
 *     read-only `notification-statuses` particle resource and its `replay` op, registered by
 *     {@see Resources::register()}. Owning the view is not owning the durability: nothing here
 *     writes a delivery row, and the attribute deliberately sits on a Data class in THIS package
 *     rather than on the beam-agnostic `rushing/*` model.
 *
 * MIGRATIONS: this package ships NONE, and that is the point (beam-facade ticket 77, ruled by 58).
 * It once carried a `beam_notifications` outbox as a publish-only stub — DDL inherited from the
 * dissolved submissions package and adopted here on 2026-08-03, five days AFTER the 2026-07-29 ruling
 * (BWP-05) that deleted the outbox in favour of `rushing/laravel-notification-status`. Four estate-wide
 * sweeps then made the corpse steadily more conformant, because no sweep predicate asks whether its
 * subject should exist. Every published copy was empty at every host, and the last writer came down
 * with tower's outbox stack (ticket 76), so the stub was deleted rather than maintained.
 *
 * Durability therefore lives entirely in the incumbent's `notification_statuses` table — which is why
 * the install step below publishes the INCUMBENT's two tags alongside this package's own: a delegated
 * substrate is nobody's artifact under the manifest's "a package registers its own" model, and the
 * arm that made the delegation is the honest owner of getting it installed (58 Q7).
 */
class BeamNotificationsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-notifications')
            // Nested config namespace (beam-write-pipeline ticket 07): config/beam/notifications.php,
            // read as config('beam.notifications.*') — the beam family reads as one.
            ->hasConfigFile('beam/notifications');
        // Config only. This package registers no migrations because it ships none (see class docblock):
        // its durability substrate belongs to rushing/laravel-notification-status, and the install step
        // in packageBooted() is what gets that substrate published.
        //
        // Do not restore the registration call here even in prose — StubMigrationsAudit's predicate for
        // it is a plain str_contains over this file's source, so merely NAMING the method in a comment
        // reads as a registration and flips the audit to its "ships stubs" branch.
    }

    public function packageRegistered(): void
    {
        $this->app->bind(RecipientResolver::class, DefaultRecipientResolver::class);
    }

    public function packageBooted(): void
    {
        // Self-register into beam-core's install manifest (ticket 08): splicewire:beam:install publishes this
        // package's config with the rest of the stack. beam-core never names this package — the
        // registration pushes DOWN into the manifest from here.
        //
        // THREE tags on ONE step (beam-facade 77, ruled by 58 Q7). One is this package's own; the other
        // two belong to rushing/laravel-notification-status, the incumbent this arm delegated delivery
        // durability to. Its recorder subscribes globally on boot with no `hasTable` guard, and nothing
        // in the estate registered its tags, so its table landed only where someone published by hand —
        // two hosts sent notifications straight into a missing table. The gap is structural, not
        // sequential: the manifest's model is "a package registers its own artifacts", and a DELEGATED
        // substrate is nobody's artifact.
        //
        // Deliberately NOT a second step named `rushing/laravel-notification-status`: that package has
        // never heard of beam, and naming it here would invert the manifest's load-bearing direction
        // (consumers register DOWN; beam-core never learns a consumer's name). One step, named for the
        // package that made the delegation, says the true thing — installing the notify arm publishes
        // the substrate it delegated to.
        //
        // Re-publishing at a host that already has `notification_statuses` is safe: that stub carries
        // the convergent guard (ticket 28), so a second publish converges rather than collides.
        if ($this->app->bound(BeamInstallManifest::class)) {
            $this->app->make(BeamInstallManifest::class)->register(
                package: 'splicewire/laravel-beam-notifications',
                publishTags: [
                    // `beam-notifications-migrations` is deliberately absent: package-tools registers a
                    // `<short-name>-migrations` tag only per entry in `->hasMigrations([...])`, so with no
                    // migrations left the tag does not exist. Listing it would publish nothing — the same
                    // dead-artifact rot this ticket deleted, in the manifest instead of on disk.
                    'beam-notifications-config',
                    'notification-status-config',
                    'notification-status-migrations',
                ],
                note: 'also publishes rushing/laravel-notification-status — the delivery ledger this arm delegated durability to.',
            );
        }

        // beam-notifications is itself an "operator" of the estate-wide publish-only stub migrations
        // convention — self-registers the doctor/operator check on ITS OWN migrations, same as every
        // other beam-* package registers it on theirs (guarded: a host predating the manifest still boots).
        if ($this->app->bound(BeamDoctorManifest::class)) {
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-notifications',
                BeamNotificationsMigrationsAudit::class,
            );
        }

        // The operator view of the ledger this package delegated durability to (ticket 75 / 58 Q3):
        // a framed read-only `notification-statuses` resource + the `replay` op. Self-guarded on
        // beam's particle infra and on `beam.notifications.resources.enabled`.
        Resources::register();

        if (! config('beam.notifications.listen', true)) {
            return;
        }

        // The ONE post-persist trigger: every beam write path emits the persisted-particle event. No dead
        // model-creation listener — a persisted record, whatever produced it, can now notify.
        //
        // NOTE (beam-particle-rename 07): the event is now canonically `BeamParticlePersisted`. Laravel
        // matches events by CONCRETE class name, and beam-core's ParticleWriter dispatches
        // `new BeamParticlePersisted(...)`, so this listen MUST name the same concrete class — done
        // ATOMICALLY at T07 (the class rename + its dispatch flip + this listen, together). A listener
        // registered under the old (now-removed) event name would never fire.
        Event::listen(BeamParticlePersisted::class, [NotifyOnSubmission::class, 'handle']);
    }
}
