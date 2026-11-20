<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

/**
 * A reader whose person lives on a SECOND named connection, as the flagship's central user lives on `central` while a
 * tenant connection is the ambient one (decision 02, P5). Its rows must be read there, through its own relation, and
 * never from the bare model on the default connection (attention-surfaces 09 amend, fix 1).
 */
class SecondConnectionReader extends ReaderUser
{
    protected $connection = 'second';
}
