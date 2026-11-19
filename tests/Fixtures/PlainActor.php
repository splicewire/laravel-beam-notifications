<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An actor that is neither notifiable nor a replica of a central person: it carries no `notifications()`
 * relation and names no central model. The default port resolves it to nobody.
 */
class PlainActor extends Model
{
    use HasUuids;

    protected $table = 'test_users';

    protected $guarded = [];

    public $timestamps = false;
}
