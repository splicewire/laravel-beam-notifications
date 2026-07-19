<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * A stand-in for a persistent, notifiable member model (what beam-accounts' resolver would
 * return for `to_roles` / `to_teams`). Uses Laravel's Notifiable so it can carry full channels
 * including the `database` inbox — the structural constraint from §2.
 */
class TeamMemberUser extends Model
{
    use HasUuids;
    use Notifiable;

    protected $table = 'test_users';

    protected $guarded = [];

    public $timestamps = false;
}
