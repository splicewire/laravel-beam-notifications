<?php

namespace Splicewire\Beam\Notifications;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Events\SchemaRecordPersisted;
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
 * boot(): listen on {@see SchemaRecordPersisted} (the ONE post-persist signal every beam write
 * path emits — ADR-0150 / beam-write-pipeline ticket 05), gated by config; publish config.
 *
 * REWIRE (ticket 05): the old trigger was `eloquent.created: Splicewire\Beam\Models\BeamSubmission`
 * — a model ADR-0138 retired, so the listener fired on a corpse. It now listens on the generic
 * `SchemaRecordPersisted` event, so ANY persisted record (public intake, Frame edit, an adopted CRUD
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
        $this->mergeConfigFrom(__DIR__.'/../config/beam-notifications.php', 'beam-notifications');

        $this->app->bind(RecipientResolver::class, DefaultRecipientResolver::class);
        $this->app->bind(SchemaResolver::class, RegistrySchemaResolver::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/beam-notifications.php' => $this->app->configPath('beam-notifications.php'),
            ], 'beam-notifications-config');
        }

        if (! config('beam-notifications.listen', true)) {
            return;
        }

        // The ONE post-persist trigger: every beam write path emits SchemaRecordPersisted. No dead
        // model-creation listener — a persisted record, whatever produced it, can now notify.
        Event::listen(SchemaRecordPersisted::class, [NotifyOnSubmission::class, 'handle']);
    }
}
