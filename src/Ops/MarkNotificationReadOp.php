<?php

namespace Splicewire\Beam\Notifications\Ops;

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Splicewire\Beam\Notifications\Data\StoredNotificationData;
use Splicewire\Beam\Particle\ActionAffordance;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/**
 * `POST {notifications}/{id}/read`: the reader marks one of their notifications read (attention-surfaces 10). Minted
 * with `splicewire:beam:make:particle-op --kind=write --affordance=action` and moved into this package.
 *
 * The row is found through the `notifications` resource's backing, the reader's own rows on their own connection, so
 * another person's row is not found (404) before anything is asked. `ability: 'update'` then asks
 * {@see \Splicewire\Beam\Notifications\Authorization\StoredNotificationPolicy} (or the host's policy) about the row.
 *
 * `input: false`: a confirm-only button; the row comes from the URL. It answers the row as the list shows it.
 */
#[ParticleOp(
    resource: 'notifications',
    name: 'read',
    summary: 'Mark one of your notifications read',
    description: 'Marks the notification read for the person the request acts for. Another person\'s notification is not found.',
    kind: OperationKind::Write,
    ability: 'update',
    affordance: new ActionAffordance(label: 'Mark read'),
    input: false,
    output: StoredNotificationData::class,
)]
class MarkNotificationReadOp
{
    public static function handle(DatabaseNotification $model, Request $request, mixed $actor): StoredNotificationData
    {
        $model->markAsRead();

        return StoredNotificationData::project($model);
    }
}
