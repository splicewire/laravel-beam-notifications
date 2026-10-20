<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

/**
 * A value that records the magic methods PHP runs on it. Planted inside a stored notification, the tripwires prove
 * the delivery ledger names a row without reviving anything the row stored: if PHP wakes, unserializes or
 * destructs one, it lands in {@see self::$fired}. Each subclass carries one way in, because PHP prefers
 * `__unserialize` to `__wakeup` when a class has both, and a destructor runs whichever way the value was built.
 */
abstract class Tripwire
{
    /** @var list<string> "Class::method" for every magic method PHP ran, in order. */
    public static array $fired = [];

    public function __destruct()
    {
        self::$fired[] = static::class.'::__destruct';
    }
}
