<?php

namespace Splicewire\Beam\Notifications\Recipients;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Notifications\Contracts\NotifiableForActor;

/**
 * The default "notifiable for this actor", exactly as decision 02 ruled it (ruling 3; ticket 08's notes):
 *
 * 1. an actor that is itself notifiable is returned;
 * 2. an actor that names a central model and no tenant model (a tenant-side replica, stancl's Syncable shape) is
 *    looked up there by its global identifier, and the row found is returned only if it is notifiable;
 * 3. anything else resolves to nobody.
 *
 * It needs no other package. The replica reading is the one beam-accounts' `PreferredLocale::central()` already makes,
 * restated here on method names alone so no edge onto beam-accounts or beam-tenancy is added. Nothing falls back: a
 * replica whose central row is missing resolves to nobody, never to the first person in the table.
 */
class DefaultNotifiableForActor implements NotifiableForActor
{
    public function resolve(mixed $actor): ?Model
    {
        if (! $actor instanceof Model) {
            return null;
        }

        if ($this->notifiable($actor)) {
            return $actor;
        }

        if (! method_exists($actor, 'getCentralModelName') || method_exists($actor, 'getTenantModelName')) {
            return null;
        }

        $centralModel = $actor->getCentralModelName();

        if (! is_string($centralModel) || ! is_a($centralModel, Model::class, true)) {
            return null;
        }

        $keyName = method_exists($actor, 'getGlobalIdentifierKeyName') ? $actor->getGlobalIdentifierKeyName() : null;
        $key = method_exists($actor, 'getGlobalIdentifierKey') ? $actor->getGlobalIdentifierKey() : null;

        if (! is_string($keyName) || $keyName === '' || $key === null || $key === '') {
            return null;
        }

        $central = $centralModel::query()->where($keyName, $key)->first();

        return $central instanceof Model && $this->notifiable($central) ? $central : null;
    }

    /** Notifiable as Laravel's `database` channel needs it: something to `notify()` and a relation to store under. */
    private function notifiable(Model $model): bool
    {
        return method_exists($model, 'notify') && method_exists($model, 'notifications');
    }
}
