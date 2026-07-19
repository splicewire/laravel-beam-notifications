<?php

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Splicewire\Beam\Notifications\Notifications\BeamNotification;
use Splicewire\Beam\Notifications\Tests\Fixtures\BrandedOverrideNotification;

it('synthesizes a generic BeamNotification from x-beam-notify (zero-PHP path)', function () {
    Notification::fake();

    fireSubmission(
        notify: [
            'to' => ['ops@site.test'],
            'channels' => ['mail'],
            'subject' => 'New {{ schema.title }} submission',
            'template' => '{{ payload.name }} wrote: {{ payload.message }}',
        ],
        payload: ['name' => 'Ada', 'message' => 'hi'],
    );

    Notification::assertSentOnDemand(
        BeamNotification::class,
        function (BeamNotification $n, array $channels, object $notifiable) {
            // rendered subject proves the keyword drove the generic renderer
            $mail = $n->toMail($notifiable);

            return $mail->subject === 'New Contact submission'
                && $n->channels === ['mail'];
        },
    );
});

it('dispatches the host FQCN override instead of the generic when x-beam-notify.notification is set', function () {
    Notification::fake();

    fireSubmission(
        notify: [
            'to' => ['ops@site.test'],
            'notification' => BrandedOverrideNotification::class,
            // subject/template are ignored when an override is named
            'subject' => 'IGNORED',
            'template' => 'IGNORED',
        ],
        payload: ['name' => 'Ada'],
    );

    Notification::assertSentOnDemand(BrandedOverrideNotification::class);
    Notification::assertNotSentTo(new AnonymousNotifiable, BeamNotification::class);
});

it('renders the generic mail subject/body from the payload via the logic-less interpolator', function () {
    Notification::fake();

    fireSubmission(
        notify: [
            'to' => ['ops@site.test'],
            'subject' => 'Hi {{ payload.name }}',
            'template' => 'msg: {{ payload.message }}',
        ],
        payload: ['name' => 'Ada', 'message' => '<b>x</b>'],
    );

    Notification::assertSentOnDemand(
        BeamNotification::class,
        function (BeamNotification $n, array $channels, object $notifiable) {
            $mail = $n->toMail($notifiable);

            // subject interpolated; body interpolated AND html-escaped (untrusted payload)
            return $mail->subject === 'Hi Ada'
                && collect($mail->introLines)->contains('msg: &lt;b&gt;x&lt;/b&gt;');
        },
    );
});
