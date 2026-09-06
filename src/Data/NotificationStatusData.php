<?php

namespace Splicewire\Beam\Notifications\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Rushing\DataFilters\Attributes\Sortable;
use Rushing\NotificationStatus\Enums\NotificationDeliveryStatus;
use Rushing\NotificationStatus\Models\NotificationStatus;
use Rushing\PermissionCascade\Policies\BaseModelPolicy;
use Schemastud\DataSchemas\Attributes\Description;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The operator view of the delivery ledger (beam-facade ticket 75, resolving 58 Q4) — one row per
 * `(notification, notifiable, channel)`, read straight off `rushing/laravel-notification-status`.
 *
 * ## Why this lives in beam-notifications and fronts another package's model
 * beam-notifications DELEGATED durability to the incumbent (`rushing/laravel-notification-status`,
 * a hard require) and writes no tracking code of its own, so it owns the operator view of the ledger
 * it delegated to (58 Q3). The reverse — putting the attribute on the `rushing/*` model — would make
 * a deliberately beam-agnostic recorder depend on `splicewire/laravel-beam`, inverting the family
 * layering. Fronting another package's model from outside is well-precedented: beam-accounts
 * registers Sanctum's `PersonalAccessToken`, beam-media spatie's `Media`, beam-tenancy `Tenant`.
 *
 * ## `readOnly`, and it is a truth claim rather than a taste
 * The rows are a recorder's OUTPUT. A hand-edited `status` is a lie about a delivery that did or did
 * not happen, so there is no create/edit/delete surface at all — the one write this ledger accepts is
 * the `replay` operation ({@see \Splicewire\Beam\Notifications\Ops\ReplayNotificationStatusOp}),
 * which re-enters Laravel's own send pipeline and lets the recorder write the outcome.
 *
 * ## What is deliberately NOT on the wire
 * `notification` is a serialized notification instance and `last_error` runs to 1000 chars; neither
 * belongs on a list projection. The serialized blob is projected as its PREDICATE instead —
 * `replayable`, which is exactly the package's own `notification !== null` signal — so an operator can
 * see whether a row CAN be replayed without the payload travelling. The error text stays readable
 * through the package's own logs; a host that needs it on the wire widens this class deliberately
 * (04's "widening a surface is a decision").
 *
 * ## `filterable: false`, stated rather than defaulted
 * `filterable: true` requires a data-filters REGISTRATION, and a package-declared `#[ResourceFilter]`
 * only registers where the HOST has opted its discover paths in (beam-taxonomy's `tags` is the
 * estate's one instance) — a key registered in no tier makes `ResourceRegistry::get()` throw and the
 * index break on first request. So the list ships unfaceted and the default order is single-sourced
 * from `#[Sortable(default: true)]`, which the non-filterable index path reads identically. Trigger
 * for revisiting: an operator asks to filter by `status`/`channel`/`notificationType`, at which point
 * the resource gains a `ResourceQuery` and a registration in the same act.
 *
 * ## The model class is the OOTB one
 * `notification-status.models.notification_status` is swappable; `backing:` is a static class-string
 * and cannot follow it. A host that binds a different ledger model registers its own read resource —
 * the same caveat `laravel-beam-accounts`' `AccessGrantData` carries about `grant_model`.
 */
#[ParticleResource(
    key: 'notification-statuses',
    backing: NotificationStatus::class,
    // A non-empty label is what makes the resource FRAMED — it lights up the @schemastud/frame editor
    // as a navigable list. Read-only there by construction (see the class docblock).
    label: 'Deliveries',
    group: 'Ops',
    icon: 'send',
    section: 'ops',
    filterable: false,
    readOnly: true,
    singularLabel: 'Delivery',
)]
class NotificationStatusData extends BeamData
{
    public function __construct(
        // The default sort rides `id` only as an attachment point — `#[Sortable]` targets a PROPERTY,
        // and this read shape projects no `createdAt`. `name`/`column` both say `created_at`, so the
        // public sort key and the ORDER BY are the row's age, not its uuid. Same construction as
        // `AccessGrantData`; widening the shape just to hang the attribute somewhere would change the
        // wire. Recency-first is the operator's order: the newest failures are the ones being triaged.
        #[Sortable(name: 'createdAt', column: 'created_at', default: true, direction: 'desc')]
        #[Description('The ledger row id.')]
        public string $id,
        #[Description("Laravel's per-send notification UUID — the correlation key a replay preserves.")]
        public string $notificationId,
        #[Description('FQCN of the notification class that was sent.')]
        public string $notificationType,
        #[Description('The channel the delivery went out on, e.g. mail.')]
        public string $channel,
        #[Description('Morph alias of the recipient. Null for an on-demand route with no model behind it.')]
        public ?string $notifiableType,
        #[Description('Key of the recipient record, as a string. Null for an on-demand route.')]
        public ?string $notifiableId,
        #[Description('Delivery state: pending, sent, failed or given_up.')]
        public string $status,
        #[Description('How many send attempts this row has recorded.')]
        public int $attempts,
        #[Description('Whether a serialized notification instance was stored, so the row can be replayed at all.')]
        public bool $replayable,
        #[Description('When the send entered the pipeline.')]
        public ?string $queuedAt,
        #[Description('When the channel reported the delivery sent.')]
        public ?string $sentAt,
        #[Description('When the last attempt failed.')]
        public ?string $failedAt,
        #[Description('When the retry policy gave up on the row. Set means terminal — never replayed again.')]
        public ?string $givenUpAt,
    ) {}

    /**
     * The read guard for this resource's `index` (registry-kernel 72).
     *
     * ⚠️ **`filterable: false` means this closure is the ONLY thing standing between an authenticated
     * caller and every row in the ledger.** {@see \Splicewire\Beam\Http\Particle\ParticleController}
     * says so at its `index`: a non-filterable resource has no data-filters query to gate it, so
     * "without [a scope] the list would return every row across all callers." Measured before this
     * existed, gate CLOSED, with real `['auth']` middleware: `index` returned **200 and the entire
     * ledger** to a user holding no ability, while `show` on those same rows returned **403**. That
     * asymmetry was the tell — `show` fails closed on the missing policy, `index` failed open on it.
     *
     * The guard is COMPUTED from the cascade rather than hand-written, which is why this is four lines
     * and not a bespoke owner filter. A delivery-ledger row has no steward: `notifiable_type`/
     * `notifiable_id` name the RECIPIENT of a notification, not someone with authority over the record,
     * and both are nullable. Filtering to "your own rows" would quietly turn an operator ledger
     * (`group: 'Ops'`) into a personal one. What the cascade does on an ownerless, visibility-less model
     * is exactly the operator semantics wanted — measured in `LedgerReadGuardTest`:
     *
     *     guest (null user)                    0 rows
     *     authenticated, holding no token      0 rows
     *     operator holding the class token     every row
     *
     * ⚠️ **Fail-CLOSED on a missing policy, and that is a deliberate divergence from the estate's other
     * eleven copies of this idiom.** `CalendarData::scope()` and `RankTreeData::scope()` are
     * byte-identical to each other and call `Gate::getPolicyFor($model)->scopeForUser(...)` with no null
     * check, which fatals where no policy is bound. Neither posture is written down anywhere, which is
     * registry-kernel 72's general half. Here the answer is not in doubt: a ledger that cannot resolve
     * its own guard must show nothing.
     */
    public static function scope(Builder $query): Builder
    {
        $model = config('notification-status.models.notification_status', NotificationStatus::class);
        $policy = Gate::getPolicyFor($model);

        if (! $policy instanceof BaseModelPolicy) {
            return $query->whereRaw('1 = 0');
        }

        return $policy->scopeForUser($query, Auth::user());
    }

    /**
     * Row → wire. Typed on `Model` rather than the concrete ledger class because the convention
     * signature is the framework's and a host may bind a subclass; every attribute read below is one
     * the ledger's own migration defines.
     */
    public static function project(Model $row): self
    {
        return new self(
            id: (string) $row->getKey(),
            notificationId: (string) $row->notification_id,
            notificationType: (string) $row->notification_type,
            channel: (string) $row->channel,
            notifiableType: $row->notifiable_type,
            notifiableId: $row->notifiable_id === null ? null : (string) $row->notifiable_id,
            // The column is cast to an enum on the shipped model and would be a bare string on a host
            // model that dropped the cast; both answer with the same closed vocabulary.
            status: $row->status instanceof NotificationDeliveryStatus
                ? $row->status->value
                : (string) $row->status,
            attempts: (int) $row->attempts,
            // The predicate, not the payload — see the class docblock.
            replayable: $row->notification !== null,
            queuedAt: $row->queued_at?->toIso8601String(),
            sentAt: $row->sent_at?->toIso8601String(),
            failedAt: $row->failed_at?->toIso8601String(),
            givenUpAt: $row->given_up_at?->toIso8601String(),
        );
    }
}
