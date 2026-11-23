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
 * An UNSAVED instance is answered the same way (attention-surfaces 30). Frame's manifest asks whether the actor may press
 * the record action Mark read before any row exists, and beam asks that with a fresh, unsaved instance of the backing's
 * model; it belongs to nobody, so the row check would refuse everyone and the button would never draw. The mount always
 * passes the real, saved row, which keeps the ownership check.
 *
 * THE HOST-READ CONTRACT: a host that binds its own policy for {@see DatabaseNotification} must accept `update` called
 * with NO second argument, because read-all asks it of the model class (`abilityModel:`), not of a row. Give the row
 * parameter a default (`?DatabaseNotification $notification = null`), as this class does, and answer an unsaved
 * instance as that class-level question too, or Mark read will not draw.
 *
 * Deny-default stays for every row: this is a policy, so a scoped credential is narrowed by the derived
 * `{model}.update` name (particle doctrine, credential-scope). It is a HOST-READ INTERFACE: the provider binds it only
 * when the Gate has no policy for {@see DatabaseNotification} once the application has booted, bound or guessed, so a
 * host's own policy wins (the bind-if-absent shape of 08's port; attention-surfaces 29).
 */
class StoredNotificationPolicy
{
    public function __construct(private NotifiableForActor $notifiables) {}

    public function update(?Authenticatable $actor, ?DatabaseNotification $notification = null): bool
    {
        // An UNSAVED instance is no row anybody owns: it is beam's class-level probe for a record action (the manifest
        // asks with a fresh instance of the backing's model, ParticleActionAuthorizer), so it is answered like null.
        // A saved row keeps the ownership check below; the mount always passes the real row (attention-surfaces 30).
        if ($notification === null || ! $notification->exists) {
            return $actor !== null;
        }

        $notifiable = $this->notifiables->resolve($actor);

        return $notifiable !== null
            && $notification->getAttribute('notifiable_type') === $notifiable->getMorphClass()
            && (string) $notification->getAttribute('notifiable_id') === (string) $notifiable->getKey();
    }
}
