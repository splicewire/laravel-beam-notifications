<?php

namespace Splicewire\Beam\Notifications\Ops;

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Splicewire\Beam\Localization\CodedAuthorizationException;
use Splicewire\Beam\Notifications\Contracts\NotifiableForActor;
use Splicewire\Beam\Notifications\Data\MarkedReadData;
use Splicewire\Beam\Notifications\Localization\NotificationError;
use Splicewire\Beam\Particle\ActionAffordance;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;

/**
 * `POST {notifications}/read-all`: the reader marks all of their unread notifications read (attention-surfaces 10).
 * Minted with `splicewire:beam:make:particle-op --kind=write --no-subject --affordance=action` and moved into this
 * package.
 *
 * It has no row, so it is mounted ABOVE the row routes ({@see \Splicewire\Beam\Notifications\Resources::routes()}).
 * `ability: 'update'` is asked of the model class (`abilityModel:`), so a scoped credential is narrowed by the
 * derived name, and {@see \Splicewire\Beam\Notifications\Authorization\StoredNotificationPolicy} (or the host's
 * policy) decides.
 *
 * The rows it changes are the resolved notifiable's own unread rows, through its own relation on its own connection,
 * the same resolution the list and the summary use. An actor the port cannot place is refused HERE, with the coded
 * `notification_reader_unresolved`, never applied to the whole table: a policy cannot carry a code through beam's
 * operation transport.
 */
#[ParticleOp(
    resource: 'notifications',
    name: 'read-all',
    summary: 'Mark all of your notifications read',
    description: 'Marks every unread notification of the person the request acts for read, and answers how many.',
    kind: OperationKind::Write,
    ability: 'update',
    affordance: new ActionAffordance(label: 'Mark all read'),
    input: false,
    output: MarkedReadData::class,
    subject: NoSubject::class,
    abilityModel: DatabaseNotification::class,
)]
class MarkAllNotificationsReadOp
{
    public static function handle(mixed $subject, Request $request, mixed $actor): MarkedReadData
    {
        $notifiable = app(NotifiableForActor::class)->resolve($actor ?? Auth::user());

        if ($notifiable === null) {
            throw new CodedAuthorizationException(NotificationError::ReaderUnresolved);
        }

        // The list's own relation: the port guarantees `notifications()`, not `unreadNotifications()`.
        return new MarkedReadData(marked: $notifiable->notifications()->whereNull('read_at')->update(['read_at' => now()]));
    }
}
