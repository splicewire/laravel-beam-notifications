<?php

namespace Splicewire\Beam\Notifications\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The notifiable for this actor: the person a request's actor stands for, whose `notifications()` relation holds the
 * rows that actor may read (attention-surfaces decision 02, ruling 3).
 *
 * The actor and the notifiable are not always the same model. In a tenant request the guard can carry a tenant-side
 * replica of the person, which has no `notifications()` relation; the rows belong to the central person behind it.
 *
 * Returns null when the actor stands for nobody this port can place. Null means no rows and refused writes, never the
 * whole table, and never another person. The package binds {@see \Splicewire\Beam\Notifications\Recipients\DefaultNotifiableForActor};
 * a host or the tenancy tier may bind its own.
 */
interface NotifiableForActor
{
    public function resolve(mixed $actor): ?Model;
}
