<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;

/** A host's own delivery-ledger policy, found only by the host's guesser ({@see HostGuessingLedgerProvider}). */
class HostGuessedLedgerPolicy
{
    public function viewAny(?Authenticatable $actor): bool
    {
        return false;
    }
}
