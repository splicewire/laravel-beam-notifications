<?php

namespace Schemastud\Beam\Notifications\Tests\Fixtures;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A host-authored override (the `x-beam-notify.notification` FQCN case, §1). It receives the
 * same {payload, schema, submission} context beam resolved, and owns its own message — beam
 * still owns WHO (recipient resolution) and tracking.
 */
class BrandedOverrideNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $channels
     */
    public function __construct(
        public array $context = [],
        public array $channels = [],
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Branded: host owns this message')
            ->line('name='.($this->context['payload']['name'] ?? ''));
    }
}
