<?php

namespace Schemastud\Beam\Notifications\Support;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Facades\Notification;
use Schemastud\Beam\Notifications\Contracts\RecipientResolver;
use Schemastud\Beam\Notifications\Keywords;
use Schemastud\Beam\Notifications\Notifications\BeamNotification;
use Schemastud\Beam\Notifications\Recipients\Recipient;

/**
 * Turns a resolved `x-beam-notify` keyword + its context into an actual send:
 *
 *  1. resolve recipients via the bound {@see RecipientResolver} (§2) — the built-in handles
 *     `to:`; beam-accounts' resolver adds `to_roles`/`to_teams`;
 *  2. build the message — the generic {@see BeamNotification} rendered from the keyword, OR
 *     the host FQCN named by `x-beam-notify.notification` (§1). The override REPLACES
 *     subject/template; beam still owns WHO (this resolution) and tracking (pkg 25);
 *  3. dispatch through Laravel's native pipeline (`Notification::send` for models,
 *     `Notification::route('mail', ...)` for on-demand addresses) — so pkg 25's global
 *     event subscriber records delivery status with ZERO coupling code here.
 *
 * Whatever is sent is a real Laravel Notification through Notification::send/route (the
 * only obligation on beam, spec §4), so NotificationSending/Sent/Failed fire and pkg 25
 * records the pending/sent/failed/given_up lifecycle automatically.
 */
class NotificationDispatcher
{
    public function __construct(protected RecipientResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $notify  The parsed `x-beam-notify` keyword.
     * @param  array<string, mixed>  $context  The {payload, schema, submission} context.
     * @param  list<string>  $defaultChannels
     */
    public function dispatch(array $notify, array $context, array $defaultChannels): void
    {
        $recipients = $this->resolver->resolve($notify, $context);

        if ($recipients === []) {
            return;
        }

        $channels = $this->channels($notify, $defaultChannels);

        foreach ($recipients as $recipient) {
            $notification = $this->makeNotification($notify, $context, $channels, $recipient);

            $this->send($recipient, $notification);
        }
    }

    /**
     * The host FQCN override (§1) wins; otherwise the generic renderer. The override is
     * resolved from the container so it can declare constructor dependencies; the generic
     * carries the rendered channels/subject/template/context.
     *
     * @param  array<string, mixed>  $notify
     * @param  array<string, mixed>  $context
     * @param  list<string>  $channels
     */
    protected function makeNotification(array $notify, array $context, array $channels, Recipient $recipient): LaravelNotification
    {
        $override = $notify['notification'] ?? null;

        if (is_string($override) && $override !== '' && class_exists($override)) {
            return app()->make($override, [
                'context' => $context,
                'channels' => $channels,
            ]);
        }

        return new BeamNotification(
            channels: $channels,
            subject: isset($notify['subject']) ? (string) $notify['subject'] : null,
            template: isset($notify['template']) ? (string) $notify['template'] : null,
            context: $context,
        );
    }

    protected function send(Recipient $recipient, LaravelNotification $notification): void
    {
        if ($recipient->isNotifiable()) {
            Notification::send($recipient->notifiable, $notification);

            return;
        }

        // On-demand address (mail-only): pkg 25 stores notifiable = null for these. Sent via
        // the Notification FACADE (not AnonymousNotifiable::notify()) so it flows through the
        // same dispatcher the fake swaps — otherwise on-demand sends bypass Notification::fake().
        Notification::send(
            (new AnonymousNotifiable)->route('mail', $recipient->address),
            $notification,
        );
    }

    /**
     * @param  array<string, mixed>  $notify
     * @param  list<string>  $defaultChannels
     * @return list<string>
     */
    protected function channels(array $notify, array $defaultChannels): array
    {
        $declared = $notify['channels'] ?? null;

        if (is_array($declared) && $declared !== []) {
            return array_values(array_map('strval', $declared));
        }

        return $defaultChannels;
    }

    /**
     * The keyword constant, re-exposed so callers reference one place.
     */
    public static function keyword(): string
    {
        return Keywords::Notify;
    }
}
