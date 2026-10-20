<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

/** The legacy way in: a value restored through `__wakeup`. */
class WakeupTripwire extends Tripwire
{
    public function __wakeup(): void
    {
        self::$fired[] = static::class.'::__wakeup';
    }
}
