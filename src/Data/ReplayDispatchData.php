<?php

namespace Splicewire\Beam\Notifications\Data;

use Illuminate\Database\Eloquent\Model;
use Rushing\NotificationStatus\Enums\NotificationDeliveryStatus;
use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Data;

/**
 * What the `replay` operation answers with — the declared `output:` slot of
 * {@see \Splicewire\Beam\Notifications\Ops\ReplayNotificationStatusOp}.
 *
 * A Task's default envelope is a bare `{ queued: true|false }`, which is true but tells an operator
 * nothing about the row they just acted on. Because `?async` decides whether the send has ALREADY
 * happened by the time the response is built, the honest shape carries both: the dispatch mode AND
 * the row's state as re-read after dispatch. On a queued run that state is the PRE-replay one (the
 * job has not run yet) — which is why `queued` ships beside it rather than being inferred from it.
 */
class ReplayDispatchData extends Data
{
    public function __construct(
        #[Description('True when the replay was queued; false when it ran inline (?async=false).')]
        public bool $queued,
        #[Description('The ledger row the replay was dispatched for.')]
        public string $id,
        #[Description('The row status as re-read after dispatch. On a queued run this still predates the replay.')]
        public string $status,
        #[Description('The row attempt count as re-read after dispatch.')]
        public int $attempts,
    ) {}

    public static function forRow(bool $queued, Model $row): self
    {
        return new self(
            queued: $queued,
            id: (string) $row->getKey(),
            status: $row->status instanceof NotificationDeliveryStatus
                ? $row->status->value
                : (string) $row->status,
            attempts: (int) $row->attempts,
        );
    }
}
