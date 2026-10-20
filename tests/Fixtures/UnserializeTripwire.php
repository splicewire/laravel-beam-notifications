<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

/** The current way in: a value restored through `__unserialize`. */
class UnserializeTripwire extends Tripwire
{
    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        return ['armed' => true];
    }

    /** @param  array<string, mixed>  $data */
    public function __unserialize(array $data): void
    {
        self::$fired[] = static::class.'::__unserialize';
    }
}
