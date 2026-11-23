<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Rushing\NotificationStatus\Models\NotificationStatus;

/** A host provider that maps its delivery-ledger policy by `Gate::guessPolicyNamesUsing()`, binding nothing explicitly. */
class HostGuessingLedgerProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::guessPolicyNamesUsing(fn (string $model): array => $model === NotificationStatus::class
            ? [HostGuessedLedgerPolicy::class]
            : []);
    }
}
