<?php

namespace Splicewire\Beam\Notifications;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Rushing\NotificationStatus\Models\NotificationStatus;
use Rushing\PermissionCascade\Policies\ConfiguredModelPolicy;
use Rushing\Popcorn\Registries\ScopesRegistrant;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Notifications\Authorization\StoredNotificationPolicy;
use Splicewire\Beam\Notifications\Config\BeamNotificationsSection;
use Splicewire\Beam\Notifications\Contracts\NotifiableForActor;
use Splicewire\Beam\Notifications\Listeners\NotifyOnSubmission;
use Splicewire\Beam\Notifications\Recipients\DefaultNotifiableForActor;
use Splicewire\Beam\Notifications\Recipients\RecipientKindRegistry;
use Splicewire\Beam\Notifications\Support\RegistrySchemaResolver;

/**
 * The notify-capability provider. "A beam can notify."
 *
 * packageRegistered(): merge config; bind the recipient-KIND registry.
 *
 * There is no rebindable resolver seam any more (beam-facade 100, built by 159). This provider used to
 * bind `RecipientResolver -> DefaultRecipientResolver` and expect beam-accounts to REBIND it wholesale
 * in order to add two keyword keys. Measured twice: nothing in the family ever did, so half the
 * `x-beam-notify` keyword threw at every host while the docblocks said otherwise. The extension point
 * is now {@see RecipientKindRegistry} — beam-accounts APPENDS `to_roles` / `to_teams` to
 * `beam.notifications.recipient-kinds`, contributing one key each instead of replacing the mechanism.
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
 * MIGRATIONS: ONE, Laravel's stored-notifications table (attention-surfaces 08; decision 02 ruling 2).
 * `create_notifications_table` is a publish-only, central-only stub keyed with `uuidMorphs()`: the rows
 * the `database` channel writes and a person's "Recent" list reads. Before it, only the flagship had the
 * table, keyed by a BIGINT its UUID users could never be stored under (decision 02, P2 and P4).
 *
 * It is NOT the outbox this package once carried and deleted (beam-facade ticket 77, ruled by 58): that
 * `beam_notifications` table was a delivery outbox, inherited from the dissolved submissions package after
 * the 2026-07-29 ruling (BWP-05) moved delivery durability to `rushing/laravel-notification-status`. It
 * stays deleted.
 *
 * The person a request's actor stands for comes from the {@see NotifiableForActor} port, bound to
 * {@see DefaultNotifiableForActor} unless a host or the tenancy tier binds its own (decision 02 ruling 3).
 *
 * Delivery durability lives entirely in the incumbent's `notification_statuses` table — which is why
 * the install step below publishes the INCUMBENT's two tags alongside this package's own: a delegated
 * substrate is nobody's artifact under the manifest's "a package registers its own" model, and the
 * arm that made the delegation is the honest owner of getting it installed (58 Q7).
 */
class BeamNotificationsServiceProvider extends PackageServiceProvider
{
    use ScopesRegistrant;

    /** Every registry write in register()/boot() that names no `$by` records this package. */
    protected function registrant(): string
    {
        return 'splicewire/laravel-beam-notifications';
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-notifications')
            // Nested config namespace (beam-write-pipeline ticket 07): config/beam/notifications.php,
            // read as config('beam.notifications.*') — the beam family reads as one.
            ->hasConfigFile('beam/notifications')
            // Central-only, top level: published to the host's bare `database/migrations/`, never `shared/`
            // or `tenant/` (see class docblock). The delivery ledger's migrations stay the incumbent's.
            ->hasMigrations(['create_notifications_table']);
    }

    public function packageRegistered(): void
    {
        // The notifiable for this actor (decision 02 ruling 3). `bindIf`: a host or the tenancy tier that
        // binds its own resolver keeps it, whichever provider registers first.
        $this->app->bindIf(NotifiableForActor::class, DefaultNotifiableForActor::class);

        // A singleton, but NOT a snapshot: ConfigRegistry reads through to the config repository on
        // every read, which is what lets beam-accounts append its kinds from a `packageBooted()` that
        // runs after this one.
        $this->app->singleton(RecipientKindRegistry::class);
    }

    public function packageBooted(): void
    {
        // Who may change a stored notification (attention-surfaces 10): only the port-resolved notifiable. Bound
        // only when the host has no policy for the model, so a host's own policy wins (the bind-if-absent shape
        // of the port above).
        //
        // "Has no policy" is asked of the Gate, not of its explicit map (attention-surfaces 29, ruling 2): a host
        // that maps policies by `Gate::guessPolicyNamesUsing()` binds nothing explicitly, and an explicit bind here
        // would then outrank its guessed policy. Asked once the application has BOOTED, because a host sets its
        // guesser in its own `AppServiceProvider::boot()`, which runs after every discovered package provider; asked
        // here, in this package's boot, `getPolicyFor()` would answer from the default guess and miss it.
        $this->app->booted(function (): void {
            if (Gate::getPolicyFor(DatabaseNotification::class) === null) {
                Gate::policy(DatabaseNotification::class, StoredNotificationPolicy::class);
            }
        });

        // Self-register into beam-core's install manifest (ticket 08): splicewire:beam:install publishes this
        // package's config with the rest of the stack. beam-core never names this package — the
        // registration pushes DOWN into the manifest from here.
        //
        // FOUR tags on ONE step (beam-facade 77, ruled by 58 Q7; attention-surfaces 08). Two are this
        // package's own (its config, and the stored-notifications table's migration); the other
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
                    // `beam-notifications-migrations` exists again because `->hasMigrations([...])` names
                    // the stored-notifications table (package-tools registers the tag per entry). A host
                    // that already committed a create of that stem keeps it: publishing resolves onto it.
                    'beam-notifications-config',
                    'beam-notifications-migrations',
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
            $this->app->make(BeamDoctorManifest::class)->stubMigrations(
                'splicewire/laravel-beam-notifications',
                self::class,
            );
        }

        // Declare the ADR-0245 Config Section for `beam.notifications` into beam-core's registry, so the
        // config census attributes every key of config/beam/notifications.php to this owned section
        // (source `registry`) instead of reporting the root undeclared. Registration only — the declared
        // shape lives in {@see BeamNotificationsSection}, and `recipient-kinds` is the host-extensible map
        // beam-accounts appends into. `callAfterResolving` so a host that never resolves the registry (no
        // census, no doctor) still boots without paying for it.
        $this->callAfterResolving(ConfigSectionRegistry::class, static function (ConfigSectionRegistry $sections): void {
            $sections->section(
                BeamNotificationsSection::class,
                owner: 'splicewire/laravel-beam-notifications',
                packageFile: dirname(__DIR__).'/config/beam/notifications.php',
            );
        });

        // DECLARE the operator view of the ledger this package delegated durability to (ticket 75 /
        // 58 Q3): a read-only `notification-statuses` resource + the `replay` op. Registration only —
        // never mounting. Self-guarded on beam's particle infra (`class_exists`) and nothing else; the
        // `beam.notifications.resources.enabled` key this comment used to name was deleted by
        // registry-kernel 70, because no host had ever set it and the surface was gated shut from birth.
        //
        // ⚠️ **This called `Resources::register()` until registry-kernel 71, and a provider is the one
        // place it must never be called from.** A provider boots OUTSIDE every route group, so the
        // mount had no ambient middleware to inherit and the package supplied `['web','auth']` to fill
        // the gap — which is a guess about the host, and the wrong one at an API-only or tenant host.
        // Mounting is now the host's act, made from the route file whose group it wants:
        //
        //     // routes/tenant.php, inside the group you want it under
        //     \Splicewire\Beam\Notifications\Resources::register();
        //
        // The measured reason it cannot be defaulted: Laravel MERGES a nested group's middleware into
        // its parent's, so a package that names a stack appends to the host's rather than choosing.
        // Bind the cascade policy onto the ledger model, so the resource's read guard has something to
        // compute from (registry-kernel 72). Without this the `scope()` on NotificationStatusData
        // fails closed and the ledger shows nothing — which is the correct failure, but not the useful one.
        //
        // ⚠️ **Bound directly rather than through `CascadePolicyRegistrar::register()`, and the reason is
        // structural.** That helper reflects for `#[UseCascadePolicy]` and returns false when the model
        // does not carry it — and the ledger model belongs to `rushing/laravel-notification-status`,
        // which does not depend on permission-cascade and must not, since it is the durable recorder and
        // has no business knowing about authorization. A package cannot annotate another package's
        // model, so the surface OWNER binds the policy instead. This is the same split 58 Q3 drew:
        // notification-status owns durability, beam-notifications owns the surface — and a read guard is
        // part of a surface, not part of a ledger.
        //
        // Guarded on `class_exists` so a host without permission-cascade boots; the resource's own
        // `scope()` then fails closed on the unbound policy, which is the posture we want in that case.
        // Never overwrites: a host that has bound its own policy for the ledger keeps it.
        // ⚠️ **Defaulted rather than read bare, and a test caught why.** In a HOST the incumbent's
        // config is loaded before this boots, because notification-status is a hard require and Laravel
        // discovers it. Under testbench it is not: the config key reads `null` here, `$ledgerModel` is
        // falsy, and the binding silently does not happen — leaving the resource's `scope()` to fail
        // closed on an unbound policy and return an EMPTY ledger, which is indistinguishable from the
        // guard working. Three tests passed on that before the default was added. Same shape as the
        // provider-list trap in AGENTS.md: testbench does not auto-discover, and the failure arrives as
        // a plausible ANSWER rather than an error.
        $ledgerModel = config('notification-status.models.notification_status', NotificationStatus::class);

        // Checked once the application has booted (attention-surfaces 29, as for the stored-notification policy above):
        // a host maps its own ledger policy in its own provider, which boots after this one, and by `Gate::policy()` or
        // by `Gate::guessPolicyNamesUsing()`. `getPolicyFor()` sees both, but only once the host has run.
        $this->app->booted(function () use ($ledgerModel): void {
            if ($ledgerModel && class_exists(ConfiguredModelPolicy::class) && Gate::getPolicyFor($ledgerModel) === null) {
                // Bound through a container key rather than by class name, because `ConfiguredModelPolicy`
                // takes the model class as a constructor argument and so cannot be auto-resolved from a
                // bare `Gate::policy($model, Policy::class)`. This mirrors `CascadePolicyRegistrar::register()`
                // exactly, minus the `#[UseCascadePolicy]` reflection this model cannot carry.
                $key = 'permission-cascade-policy:'.$ledgerModel;

                $this->app->bind($key, fn () => new ConfiguredModelPolicy($ledgerModel));

                Gate::policy($ledgerModel, $key);

                // ⛔ The morph alias for the same model — ADR-0118, amended decision 5: "registration follows
                // ownership", and the owner is whoever declares the POLICY, which is this package. That is the
                // non-obvious half here: `rushing/laravel-notification-status` ships the model but binds no
                // policy and deliberately does not depend on permission-cascade, so it cannot own this.
                //
                // Aliased INSIDE the same guard and against `$ledgerModel` rather than the imported FQCN,
                // because a host may rebind `notification-status.models.notification_status` — the policy
                // binding above already honours that, and an alias naming the hardcoded class would silently
                // disagree with it on exactly the hosts that rebind.
                //
                // Additive, never `enforceMorphMap` (20+ class-string morphs elsewhere would orphan). Key is
                // the snake_case SHORT NAME per the estate's 106 existing aliases. Measured safe: zero live
                // polymorphic rows store this class or this key anywhere in 19 schemas, no subclass exists, and
                // `notification_status` is free in the booted map.
                Relation::morphMap(['notification_status' => $ledgerModel], true);
            }
        });

        Resources::declare();

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
