<?php

namespace Splicewire\Beam\Notifications\Particle\Backing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Splicewire\Beam\Notifications\Contracts\NotifiableForActor;
use Splicewire\Beam\Particle\Backing\BacksEloquent;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\StreamsRecords;

/**
 * The `notifications` resource's backing: the signed-in reader's OWN stored notifications (attention-surfaces 09;
 * decision 02, rulings 3 and 4).
 *
 * A `ResourceBacking` class-string, so it is resolved per request (the third freeze point; beam-accounts'
 * `ConfiguredUserBacking` is the worked example). That is the only point late enough to know the actor.
 *
 * WHOSE ROWS: ticket 08's port, {@see NotifiableForActor}, resolves the notifiable for `Auth::user()`. A flagship
 * tenant request's `TenantUser` becomes the central person behind it (P18-P20); a plain host's notifiable user is
 * itself. The query is that notifiable's own `notifications()` relation, so it carries the recipient constraint and
 * runs on the notifiable's connection: the central one at the flagship, whatever tenant is initialised. It never
 * starts from the bare `DatabaseNotification` model on the ambient connection, which on a pooled tenant only reaches
 * the central table by `search_path` fall-through and on an isolated tenant does not reach it at all (P5).
 *
 * UNRESOLVED READS NOTHING: an actor the port cannot place (a guest, a stranger, a non-notifiable actor) gets a query
 * that matches no row. Nothing falls back to another person.
 *
 * Capabilities: it queries (so the list composer can apply the declared newest-first order, and the summary can count
 * through the same builder), streams through {@see BacksEloquent}, and backs one model. It declines
 * {@see \Splicewire\Beam\Particle\Backing\WritesRecords}: the resource is read-only, and marking read is ticket 10's.
 */
class OwnStoredNotificationsBacking implements BacksModel, QueriesRecords, StreamsRecords
{
    use BacksEloquent;

    public function __construct(private NotifiableForActor $notifiables) {}

    public function modelClass(): string
    {
        return DatabaseNotification::class;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters): Builder
    {
        $notifiable = $this->notifiables->resolve(Auth::user());

        if ($notifiable === null) {
            // The same model and table as a resolved read, matching nothing: an empty answer, never an error.
            return DatabaseNotification::query()->whereRaw('1 = 0');
        }

        /** @var Builder $query */
        $query = $notifiable->notifications()->getQuery();

        return $query;
    }
}
