<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Illuminate\Notifications\Notification;

/** One stored notification on the `database` channel and nowhere else: the row a "Recent" list reads. */
class StoredFixtureNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'fixture.stored', 'message' => 'notifications::fixture.stored'];
    }
}
