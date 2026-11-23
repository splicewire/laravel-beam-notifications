<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

/** A host's own notification policy, bound explicitly by {@see HostNotificationPolicyProvider}. */
class HostNotificationPolicy
{
    public function update(?Authenticatable $actor, mixed $notification = null): bool
    {
        return false;
    }
}
