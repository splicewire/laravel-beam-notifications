<?php

namespace Schemastud\Beam\Notifications;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Schemastud\Beam\Models\BeamSubmission;
use Schemastud\Beam\Notifications\Contracts\RecipientResolver;
use Schemastud\Beam\Notifications\Contracts\SchemaResolver;
use Schemastud\Beam\Notifications\Listeners\NotifyOnSubmission;
use Schemastud\Beam\Notifications\Recipients\DefaultRecipientResolver;
use Schemastud\Beam\Notifications\Support\SnapshotSchemaResolver;

/**
 * The notify-capability provider. "A beam can notify."
 *
 * register(): merge config; bind the two seams to their built-in defaults —
 *   - RecipientResolver -> DefaultRecipientResolver (address-only `to:`). beam-accounts'
 *     provider REBINDS this to its accounts-aware resolver when installed (soft dep, §2).
 *   - SchemaResolver   -> SnapshotSchemaResolver (record-carried snapshot). A host with a
 *     canonical registry rebinds it (§S).
 *
 * boot(): listen on BeamSubmission::created (the submission-only trigger, §T), gated by
 * config; publish config.
 *
 * What this provider does NOT do (by design):
 *   - it registers NO `central` channel and ships NO relay transport. `central` is only a
 *     channel-NAME string a schema may list; the satellite provider registers the real
 *     channel via Notification::extend('central', ...). A headless beam never loads that
 *     provider, so via() (BeamNotification) drops the unregistered `central` — zero relay
 *     code travels with beam (§3).
 *   - it writes NO delivery-tracking code. rushing/laravel-notification-status subscribes to
 *     Laravel's native notification events globally; the moment a BeamNotification is sent it
 *     is recorded automatically, no coupling here (§4).
 */
class BeamNotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/beam-notifications.php', 'beam-notifications');

        $this->app->bind(RecipientResolver::class, DefaultRecipientResolver::class);
        $this->app->bind(SchemaResolver::class, SnapshotSchemaResolver::class);
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

        // The submission-only trigger (§T): the ordinary Eloquent `created` event of the
        // beam submission model. No other trigger — generation / manual-edit populators are a
        // future seam, not wired here.
        Event::listen(
            'eloquent.created: '.$this->submissionClass(),
            [NotifyOnSubmission::class, 'handle'],
        );
    }

    /**
     * The configured submission model (swappable, mirroring beam's model config), so a host
     * that subclasses BeamSubmission still fires the trigger.
     */
    protected function submissionClass(): string
    {
        return config('beam.models.submission', BeamSubmission::class);
    }
}
