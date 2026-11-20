<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User;

/**
 * A signed-in actor that ticket 08's port cannot place: it is not notifiable, and it names no central model. Its
 * reads must come back empty, never someone else's rows (attention-surfaces 09, "Unresolved reads nothing").
 */
class UnplacedReader extends User
{
    use HasUuids;

    protected $table = 'test_users';

    protected $guarded = [];

    public $timestamps = false;
}
