<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** A host provider that maps its notification policy by `Gate::guessPolicyNamesUsing()`, binding nothing explicitly. */
class HostGuessingProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::guessPolicyNamesUsing(fn (string $model): array => $model === DatabaseNotification::class
            ? [HostGuessedNotificationPolicy::class]
            : []);
    }
}
