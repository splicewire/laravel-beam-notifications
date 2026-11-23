<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

/** A host's own notification policy, found only by the host's guesser ({@see HostGuessingProvider}). */
class HostGuessedNotificationPolicy
{
    public function update(?Authenticatable $actor, mixed $notification = null): bool
    {
        return false;
    }
}
