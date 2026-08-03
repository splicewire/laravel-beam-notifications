<?php

namespace Splicewire\Beam\Notifications;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Notifications\Contracts\RecipientResolver;
use Splicewire\Beam\Notifications\Contracts\SchemaResolver;
use Splicewire\Beam\Notifications\Listeners\NotifyOnSubmission;
use Splicewire\Beam\Notifications\Recipients\DefaultRecipientResolver;
use Splicewire\Beam\Notifications\Support\RegistrySchemaResolver;

/**
 * The notify-capability provider. "A beam can notify."
 *
 * register(): merge config; bind the two seams to their built-in defaults —
 *   - RecipientResolver -> DefaultRecipientResolver (address-only `to:`). beam-accounts'
 *     provider REBINDS this to its accounts-aware resolver when installed (soft dep, §2).
 *   - SchemaResolver   -> RegistrySchemaResolver (record-carried snapshot, then beam's schema
 *     registry by the record's binding). A host with a different registry rebinds it (§S).
 *
 * boot(): listen on {@see BeamParticlePersisted} (the ONE post-persist signal every beam write
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
 */
class BeamNotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nested config namespace (beam-write-pipeline ticket 07): config/beam/notifications.php,
        // read as config('beam.notifications.*') — the beam family reads as one.
        $this->mergeConfigFrom(__DIR__.'/../config/beam/notifications.php', 'beam.notifications');

        $this->app->bind(RecipientResolver::class, DefaultRecipientResolver::class);
        $this->app->bind(SchemaResolver::class, RegistrySchemaResolver::class);
    }

    public function boot(): void
    {
        $this->bootMigrations();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/beam/notifications.php' => $this->app->configPath('beam/notifications.php'),
            ], 'beam-notifications-config');
        }

        // Self-register into beam-core's install manifest (ticket 08): splicewire:beam:install publishes this
        // package's config with the rest of the stack. beam-core never names this package — the
        // registration pushes DOWN into the manifest from here.
        if ($this->app->bound(BeamInstallManifest::class)) {
            $this->app->make(BeamInstallManifest::class)->register(
                package: 'splicewire/laravel-beam-notifications',
                publishTags: ['beam-notifications-config'],
            );
        }

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

    /**
     * Home the `beam_notifications` outbox with a **ubiquitous, context-following** shape (recohere T10):
     * the SAME migration dir runs in BOTH migration passes, so the table exists identically in central
     * and every tenant. Mirrors beam-ux's BeamUxServiceProvider::bootMigrations() (named in prose, not
     * imported) / beam-core's own bootMigrations():
     *
     *  - CENTRAL — {@see loadMigrationsFrom()} registers the shared dir, so a plain `migrate` runs it.
     *  - TENANT — pushed onto Stancl's `config('tenancy.migration_parameters.--path')` array (no
     *    auto-discovery), idempotently, so `tenants:migrate` runs the same dir. (Only central forms
     *    notify, so the tenant copy stays empty — but a uniform substrate is cheaper than a branch.)
     *
     * Gated by `config('beam.notifications.register_migrations', true)` — defaults on, matching the
     * beam-family opt-out shape.
     */
    protected function bootMigrations(): void
    {
        if (! config('beam.notifications.register_migrations', true)) {
            return;
        }

        $sharedDir = realpath(__DIR__.'/../database/migrations/shared')
            ?: __DIR__.'/../database/migrations/shared';

        // Central estate — auto-discovered by `migrate`.
        $this->loadMigrationsFrom($sharedDir);

        // Tenant estate — pushed onto Stancl's `--path` array (no auto-discovery). Same dir, so the
        // table shape is identical central + tenant.
        $paths = config('tenancy.migration_parameters.--path', []);

        if (! in_array($sharedDir, $paths, true)) {
            config()->push('tenancy.migration_parameters.--path', $sharedDir);
        }
    }
}
