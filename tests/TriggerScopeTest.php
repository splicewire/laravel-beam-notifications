<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Splicewire\Beam\Notifications\Notifications\BeamNotification;

it('fires on BeamParticlePersisted when the schema carries x-beam-notify', function () {
    Notification::fake();

    fireRecordPersisted(notify: ['to' => ['ops@site.test'], 'subject' => 'S', 'template' => 'B']);

    Notification::assertSentOnDemand(BeamNotification::class);
});

it('does nothing when the schema has no x-beam-notify keyword', function () {
    Notification::fake();

    fireRecordPersisted(notify: null, payload: ['name' => 'Ada']);

    Notification::assertNothingSent();
});

it('honors the listen=false config gate', function () {
    config()->set('beam.notifications.listen', false);

    // The listener is registered at boot; the runtime gate inside handle() short-circuits.
    Notification::fake();

    fireRecordPersisted(notify: ['to' => ['ops@site.test'], 'subject' => 'S', 'template' => 'B']);

    Notification::assertNothingSent();
});

it('binds no listener to the retired BeamSubmission creation event', function () {
    // ADR-0138 retired the BeamSubmission model; the old dead-wired `eloquent.created` listener that
    // fired on that corpse must be gone (ticket 05 rewired the trigger onto BeamParticlePersisted).
    expect(Event::getListeners('eloquent.created: Splicewire\Beam\Models\BeamSubmission'))->toBe([]);
});
