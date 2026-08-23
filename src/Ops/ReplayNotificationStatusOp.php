<?php

namespace Splicewire\Beam\Notifications\Ops;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Rushing\NotificationStatus\Contracts\RetryPolicy;
use Rushing\NotificationStatus\Enums\NotificationDeliveryStatus;
use Rushing\NotificationStatus\Jobs\ReplayNotificationStatus;
use Rushing\NotificationStatus\Models\NotificationStatus;
use Splicewire\Beam\Notifications\Data\ReplayDispatchData;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/**
 * `POST notification-statuses/{id}/op/replay` — re-send one recorded notification (beam-facade
 * ticket 75, resolving 58 Q4). The operator half of the ledger surface
 * {@see \Splicewire\Beam\Notifications\Data\NotificationStatusData} declares.
 *
 * ## Why `Task`, and why the handler is three lines
 * `rushing/laravel-notification-status` already ships the unit of work as a `ShouldQueue` job, so the
 * handler RETURNS it and beam's `?async` convention supplies sync-vs-queued with no branching here:
 * `?async=false` re-sends inline, the default queues. That convention is the whole reason this is a
 * declared operation rather than a hand-rolled controller action.
 *
 * ## The three refusals, and why they are the package's rules rather than this op's
 * The op is a THIRD door onto a replay the package already opens twice (`notification-status:replay`
 * and its optional HTTP `ReplayController`), so it enforces what those two enforce, in their
 * vocabulary — a row that a CLI operator cannot replay must not become replayable by arriving over
 * beam:
 *
 *   1. **terminal** — `sent` and `given_up` "are never re-dispatched and never transition again"
 *      ({@see NotificationDeliveryStatus::isTerminal()}). `given_up` is the ledger's write-once rule;
 *      `sent` rides the same predicate because a replay would transition a state the package says is
 *      final. The CLI reaches the same outcome by construction (it selects `failed`), which is why
 *      neither existing door spells this out and this one must.
 *   2. **unreplayable** — a null `notification` means no serialized instance was stored, so there is
 *      nothing to reconstruct. The job itself already skips such a row with a log line; refusing here
 *      turns a silent no-op into a 422 an operator can read.
 *   3. **policy** — a `failed` row still answers to the host's {@see RetryPolicy}, exactly as both
 *      other doors ask it (including `ReplayController`'s single-`id` path). An operator override
 *      that outranks the policy would be a fourth rule this ledger does not have; if one is ever
 *      wanted it belongs in the package, once, not in beam's copy of the door.
 *
 * Each refusal is raised BEFORE the job is built, so a refused replay dispatches nothing at all.
 *
 * ## `input: false` is a contract, not a description
 * The op takes its subject from the URL and nothing else. Beam enforces the declaration — a request
 * carrying a body is rejected rather than silently ignored — with `?async` excluded, being beam's own
 * parameter rather than the caller's payload.
 *
 * ## The ability is deny-default
 * `update` is resolved against the ledger row through beam's `AbilityResolver`, so a host that has
 * registered no policy for the ledger model gets a 403 until it does. That is the intended posture
 * for a surface that re-sends mail to real recipients.
 *
 * Mounted by {@see \Splicewire\Beam\Notifications\Resources::register()}.
 */
#[ParticleOp(
    resource: 'notification-statuses',
    name: 'replay',
    kind: OperationKind::Task,
    model: NotificationStatus::class,
    ability: 'update',
    input: false,
    output: ReplayDispatchData::class,
)]
class ReplayNotificationStatusOp
{
    public static function handle(NotificationStatus $row, Request $request, mixed $actor): ReplayNotificationStatus
    {
        self::assertReplayable($row);

        return new ReplayNotificationStatus((string) $row->getKey());
    }

    /**
     * A task's handler returns the JOB, so the payload beam hands back here is the dispatch outcome.
     * The projector widens that into the op's declared shape by re-reading the row beam refreshed.
     *
     * @param  array{queued: bool}  $payload
     */
    public static function respond(mixed $payload, NotificationStatus $row): ReplayDispatchData
    {
        return ReplayDispatchData::forRow((bool) ($payload['queued'] ?? true), $row);
    }

    /**
     * The three refusals, in the order that reads best in a 422: what the row IS, then what it
     * CARRIES, then what the host's policy says about it.
     */
    protected static function assertReplayable(NotificationStatus $row): void
    {
        $status = $row->status instanceof NotificationDeliveryStatus
            ? $row->status
            : NotificationDeliveryStatus::tryFrom((string) $row->status);

        if ($status?->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => "A {$status->value} delivery is terminal and is never re-dispatched.",
            ]);
        }

        if (! $row->replayable) {
            throw ValidationException::withMessages([
                'replayable' => 'This delivery stored no serialized notification instance, so there is nothing to replay.',
            ]);
        }

        if ($status === NotificationDeliveryStatus::Failed && ! app(RetryPolicy::class)->shouldRetry($row)) {
            throw ValidationException::withMessages([
                'status' => 'The retry policy declines this delivery — it is out of attempts.',
            ]);
        }
    }
}
