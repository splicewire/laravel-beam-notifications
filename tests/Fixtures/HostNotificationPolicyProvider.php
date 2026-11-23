<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** A host provider that binds its own notification policy explicitly. */
class HostNotificationPolicyProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(DatabaseNotification::class, HostNotificationPolicy::class);
    }
}
