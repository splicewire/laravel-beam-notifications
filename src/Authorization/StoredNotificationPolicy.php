<?php

namespace Splicewire\Beam\Notifications\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Splicewire\Beam\Notifications\Contracts\NotifiableForActor;

/**
 * Who may change a stored notification: only the person it belongs to (attention-surfaces 10; decision 02, ruling 4;
 * coord ruling 22:11Z).
 *
 * `update` with a ROW admits the actor only when the row is addressed to the notifiable that ticket 08's port resolves
 * for them (morph type and key). That is the SAME resolution the `notifications` list, its summary and its backing use,
 * so a write and a read cannot disagree about whose rows these are. An actor the port cannot place is refused.
 *
 * `update` with NO row is what mark-all-read asks of the model class. It admits, because that operation's population
 * is not a row the policy could judge: it is "the resolved notifiable's own unread rows", and the operation itself
 * resolves them through the port and refuses an unplaced actor with a CODED refusal (a policy cannot carry a code
 * through beam's operation transport, which answers every ability denial as a bare 403).
 *
 * THE HOST-READ CONTRACT: a host that binds its own policy for {@see DatabaseNotification} must accept `update` called
 * with NO second argument, because read-all asks it of the model class (`abilityModel:`), not of a row. Give the row
 * parameter a default (`?DatabaseNotification $notification = null`), as this class does.
 *
 * Deny-default stays for every row: this is a policy, so a scoped credential is narrowed by the derived
 * `{model}.update` name (particle doctrine, credential-scope). It is a HOST-READ INTERFACE: the provider binds it only
 * when the host has bound no policy for {@see DatabaseNotification}, so a host's own policy wins (the same
 * bind-if-absent shape as 08's port).
 */
class StoredNotificationPolicy
{
    public function __construct(private NotifiableForActor $notifiables) {}

    public function update(?Authenticatable $actor, ?DatabaseNotification $notification = null): bool
    {
        if ($notification === null) {
            return $actor !== null;
        }

        $notifiable = $this->notifiables->resolve($actor);

        return $notifiable !== null
            && $notification->getAttribute('notifiable_type') === $notifiable->getMorphClass()
            && (string) $notification->getAttribute('notifiable_id') === (string) $notifiable->getKey();
    }
}
