<?php

namespace Splicewire\Beam\Notifications\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Splicewire\Beam\Notifications\Support\Interpolator;

/**
 * The one generic notification the package synthesizes from `x-beam-notify` (spec §1) —
 * the zero-PHP path: declare a form with `x-beam-notify`, get notified, write no
 * Notification class. A host that wants a branded/rich message names its own FQCN under
 * `x-beam-notify.notification` and beam dispatches THAT instead (beam still owns who +
 * tracking; the host owns the message).
 *
 * ShouldQueue + SerializesModels so pkg 25 (rushing/laravel-notification-status) can
 * serialize it for replay, and so a slow/failing transport is pushed to the queue rather
 * than blocking the submission request (persistence already completed).
 *
 * Message rendering is the logic-less `{{ path }}` interpolator (spec §V), NOT Blade — the
 * payload is untrusted. Subject/template render over the fixed {payload, schema, submission}
 * context; mail bodies HTML-escape substituted values.
 */
class BeamNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $channels  Declared channel-name strings.
     * @param  array<string, mixed>  $context  The interpolation context.
     */
    public function __construct(
        public array $channels,
        public ?string $subject,
        public ?string $template,
        public array $context,
    ) {}

    /**
     * The declared channels INTERSECTED with the app's registered channel drivers
     * (spec §1/§3): an unregistered `central` on a headless beam (no relay provider) is
     * silently skipped, never a `Driver [central] not supported` crash. This is what lets
     * a platform-authored schema (`channels: [mail, database, central]`) degrade gracefully
     * on a headless beam — the relay simply doesn't happen.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $manager = app(ChannelManager::class);

        // Under Notification::fake() the bound ChannelManager is the fake — it has no driver()
        // resolution, so we cannot (and need not) probe registration: the fake stands in for
        // every declared channel. Pass all declared channels through in that case.
        if (! $manager instanceof ChannelManager) {
            return array_values($this->channels);
        }

        return array_values(array_filter($this->channels, function (string $channel) use ($manager): bool {
            // `mail` / `database` etc. are always resolvable; a custom driver (e.g. `central`)
            // is only "registered" if a host called Notification::extend for it. driver() throws
            // for an unregistered custom channel, which we treat as "not registered" -> drop it
            // (spec §3: an unregistered `central` on a headless beam is silently skipped).
            try {
                $manager->driver($channel);

                return true;
            } catch (\Throwable) {
                return false;
            }
        }));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->subject !== null
            ? Interpolator::render($this->subject, $this->context)
            : 'New submission';

        $message = (new MailMessage)->subject($subject);

        if ($this->template !== null) {
            // HTML-escaped: the payload is untrusted (spec §V).
            $message->line(Interpolator::render($this->template, $this->context, escape: true));
        }

        return $message;
    }

    /**
     * The default `database` inbox row for role/team recipients. Stores the rendered
     * subject + template alongside a copy of the submission context so an in-app inbox can
     * display it without re-resolving the schema.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'subject' => $this->subject !== null ? Interpolator::render($this->subject, $this->context) : null,
            'body' => $this->template !== null ? Interpolator::render($this->template, $this->context) : null,
            'context' => $this->context,
        ];
    }
}
