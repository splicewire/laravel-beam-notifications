<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\Notifiable;

/**
 * A signed-in, notifiable person keyed by a UUID: the reader the `notifications` resource lists rows for
 * (attention-surfaces 09). It is the plain-host shape (the Tower starter's), where the actor on the guard is the
 * notifiable itself; the flagship proves the tenant-replica shape under its own middleware.
 */
class ReaderUser extends User
{
    use HasUuids;
    use Notifiable;

    protected $table = 'test_users';

    protected $guarded = [];

    public $timestamps = false;
}
