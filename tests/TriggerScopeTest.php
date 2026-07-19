<?php

use Illuminate\Support\Facades\Notification;
use Splicewire\Beam\Models\SchemaRecord;
use Splicewire\Beam\Notifications\Notifications\BeamNotification;

it('fires on BeamSubmission::created', function () {
    Notification::fake();

    fireSubmission(notify: ['to' => ['ops@site.test'], 'subject' => 'S', 'template' => 'B']);

    Notification::assertSentOnDemand(BeamNotification::class);
});

it('does NOT fire when a SchemaRecord is created without a submission (submission-only trigger)', function () {
    Notification::fake();

    // Creating a record — even one carrying x-beam-notify — is not the trigger. Only a
    // BeamSubmission::created is (§T). No other populator wires notify here.
    SchemaRecord::create([
        'schema_ref' => 'contact',
        'payload' => ['name' => 'Ada'],
        'meta' => ['schema' => ['x-beam-notify' => ['to' => ['ops@site.test']]]],
    ]);

    Notification::assertNothingSent();
});

it('does nothing when the schema has no x-beam-notify keyword', function () {
    Notification::fake();

    fireSubmission(notify: null, payload: ['name' => 'Ada']);

    Notification::assertNothingSent();
});

it('honors the listen=false config gate', function () {
    config()->set('beam-notifications.listen', false);

    // The listener is registered at boot; the runtime gate inside handle() short-circuits.
    Notification::fake();

    fireSubmission(notify: ['to' => ['ops@site.test'], 'subject' => 'S', 'template' => 'B']);

    Notification::assertNothingSent();
});
