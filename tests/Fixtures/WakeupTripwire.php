<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

/**
 * A value that records being woken. Planted inside a stored notification, it proves the delivery
 * ledger names a row without instantiating what the row stored: if any magic method on ledger content
 * runs, {@see self::$woken} flips.
 */
class WakeupTripwire
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}
