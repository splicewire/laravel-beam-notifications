<?php

namespace Splicewire\Beam\Notifications\Tests;

use Illuminate\Notifications\AnonymousNotifiable;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Localization\TextNamespaces;
use Splicewire\Beam\Notifications\Notifications\BeamNotification;
use Splicewire\Beam\Notifications\Support\DeliveryLabel;
use Splicewire\Beam\Notifications\Tests\Fixtures\Tripwire;
use Splicewire\Beam\Notifications\Tests\Fixtures\UnserializeTripwire;
use Splicewire\Beam\Notifications\Tests\Fixtures\WakeupTripwire;

class LocalizationFallbackTest extends TestCase
{
    public function test_english_fallbacks_keep_the_original_mail_and_delivery_labels(): void
    {
        app()->setLocale('en');
        config(['beam.localization.pseudo' => true]);

        $this->assertSame('beam-notifications', app(TextNamespaces::class)->namespaceOf('splicewire/laravel-beam-notifications'));
        $notification = new BeamNotification(['mail'], null, 'Authored mail body.', []);
        $mail = $notification->toMail(new AnonymousNotifiable);

        $this->assertSame('New submission', $mail->subject);
        $this->assertStringContainsString('Authored mail body.', (string) $mail->render());
        $this->assertSame('New submission', DeliveryLabel::for(BeamNotification::class, null));
        $this->assertSame('New submission: Contact', DeliveryLabel::for(BeamNotification::class, serialize(
            new BeamNotification(['mail'], null, null, ['schema' => ['title' => 'Contact']]),
        )));
        $this->assertSame('Notification', DeliveryLabel::for('App\\Notification@anonymous', null));
        $this->assertSame('Notification', DeliveryLabel::for('', null));
        $this->assertSame('New submission', DeliveryLabel::DEFAULT_SUBMISSION_SUBJECT);
    }

    public function test_pseudo_fallbacks_render_owned_whole_wrappers_and_preserve_the_title(): void
    {
        config(['beam.localization.pseudo' => true]);
        $originalLocale = app()->getLocale();

        try {
            app()->setLocale('en-XA');
            $notification = new BeamNotification(['mail'], null, 'Authored mail body.', []);
            $mail = $notification->toMail(new AnonymousNotifiable);
            $labels = [
                $mail->subject,
                DeliveryLabel::for(BeamNotification::class, null),
                DeliveryLabel::for(BeamNotification::class, 'O:999:"malformed'),
                DeliveryLabel::for(BeamNotification::class, serialize(new BeamNotification(
                    ['mail'], null, null, ['schema' => ['title' => 'Contact']],
                ))),
                DeliveryLabel::for('App\\Notification@anonymous', null),
                DeliveryLabel::for('', null),
            ];

            $this->assertCount(6, $labels);
            foreach ($labels as $label) {
                $this->assertTrue(PseudoLocale::isMarked($label), $label);
            }
            $this->assertStringContainsString('Contact', $labels[3]);
            $this->assertSame(1, substr_count($labels[3], PseudoLocale::OPEN));
            $this->assertSame(1, substr_count($labels[3], PseudoLocale::CLOSE));
            $this->assertStringContainsString('Authored mail body.', (string) $mail->render());
            $this->assertSame(['subject' => null, 'body' => 'Authored mail body.', 'context' => []], $notification->toArray(new AnonymousNotifiable));
        } finally {
            app()->setLocale($originalLocale);
        }

        $this->assertSame($originalLocale, app()->getLocale());
    }

    public function test_authored_templates_context_and_serialized_snapshots_remain_unchanged_in_both_locales(): void
    {
        config(['beam.localization.pseudo' => true]);
        $context = ['payload' => ['name' => 'Ada', 'message' => '<script>alert(1)</script>'], 'schema' => ['title' => 'Authored title']];
        $notification = new BeamNotification(['mail', 'database'], 'Hi {{ payload.name }}', 'Message: {{ payload.message }}', $context);
        $serialized = serialize($notification);
        $originalLocale = app()->getLocale();

        try {
            foreach (['en', 'en-XA'] as $locale) {
                app()->setLocale($locale);
                $mail = $notification->toMail(new AnonymousNotifiable);
                $this->assertSame('Hi Ada', $mail->subject);
                $this->assertSame(['Message: &lt;script&gt;alert(1)&lt;/script&gt;'], $mail->introLines);
                $rendered = (string) $mail->render();
                $this->assertStringNotContainsString('<script>alert(1)</script>', $rendered);
                $this->assertSame('Hi Ada', DeliveryLabel::for(BeamNotification::class, $serialized));
                $this->assertSame([
                    'subject' => 'Hi Ada',
                    'body' => 'Message: <script>alert(1)</script>',
                    'context' => $context,
                ], $notification->toArray(new AnonymousNotifiable));
                $this->assertSame($serialized, serialize($notification));
                $this->assertSame($context, $notification->context);
                $this->assertSame('Reset password', DeliveryLabel::for('Illuminate\\Auth\\Notifications\\ResetPassword', null));
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_delivery_reads_stay_inert_and_authored_subject_truncation_is_unchanged(): void
    {
        config(['beam.localization.pseudo' => true]);
        app()->setLocale('en-XA');
        $notification = new BeamNotification(['mail'], 'Hello {{ payload.name }}', null, [
            'payload' => ['name' => 'Ada', 'woken' => new WakeupTripwire, 'unserialized' => new UnserializeTripwire],
        ]);
        $serialized = serialize($notification);
        Tripwire::$fired = [];

        $this->assertSame('Hello Ada', DeliveryLabel::for(BeamNotification::class, $serialized));
        $this->assertSame([], Tripwire::$fired);
        $this->assertSame($serialized, serialize($notification));

        $long = new BeamNotification(['mail'], str_repeat('Quarterly pipeline review ', 12), null, []);
        $label = DeliveryLabel::for(BeamNotification::class, serialize($long));
        $this->assertLessThanOrEqual(DeliveryLabel::MAX_LENGTH, mb_strlen($label));
        $this->assertStringStartsWith('Quarterly pipeline review', $label);
        $this->assertStringEndsWith('…', $label);
        $this->assertFalse(PseudoLocale::isMarked($label));
        $this->assertSame('New submission', DeliveryLabel::DEFAULT_SUBMISSION_SUBJECT);
    }
}
