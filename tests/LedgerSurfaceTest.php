<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rushing\NotificationStatus\Enums\NotificationDeliveryStatus;
use Rushing\NotificationStatus\Jobs\ReplayNotificationStatus;
use Rushing\NotificationStatus\Models\NotificationStatus;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Rushing\NotificationStatus\NotificationStatusServiceProvider;
use Splicewire\Beam\Notifications\Resources;
use Splicewire\Beam\Notifications\Tests\Fixtures\BrandedOverrideNotification;
use Splicewire\Beam\Notifications\Tests\Fixtures\TeamMemberUser;

/**
 * Ticket 75 — the incumbent ledger's operator surface. 58 ruled the beam-side outbox dead and
 * `rushing/laravel-notification-status` THE ledger; this proves the replacement stands: a framed
 * read-only `notification-statuses` resource plus a `replay` particle op, served by beam's generic
 * controllers off nothing but the two declarations.
 *
 * Every assertion here goes through a REQUEST rather than through the attribute — a declaration that
 * reflects correctly but never serves is what this ticket exists to avoid.
 */
beforeEach(function () {
    // Boot the incumbent (a hard require of this package, so a real host always has it) and stand up
    // its ledger table — the same setup AutoRecordStatusTest uses.
    app()->register(NotificationStatusServiceProvider::class);

    Schema::create('notification_statuses', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('notification_id')->index();
        $table->string('notification_type');
        $table->string('channel');
        $table->string('notifiable_type')->nullable();
        $table->string('notifiable_id')->nullable();
        $table->string('status')->default('pending');
        $table->unsignedInteger('attempts')->default(0);
        $table->text('last_error')->nullable();
        $table->longText('notification')->nullable();
        $table->timestamp('queued_at')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->timestamp('failed_at')->nullable();
        $table->timestamp('given_up_at')->nullable();
        $table->timestamps();
    });

    // A persistent notifiable, so a replay can resolve one live off the row's morph pair.
    Schema::create('test_users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('email');
    });

    // MOUNT the surface the way a host does, which is the only way it mounts at all since
    // registry-kernel 71: the provider DECLARES and never mounts, because a provider boots outside
    // every route group and would have to invent a middleware stack to fill the gap. Standing in for
    // the host here is the point — the test now exercises the documented call rather than a side
    // effect of booting, and it is what would have caught the six days this surface was registered
    // nowhere (registry-kernel 70).
    //
    // `group_prefix` is passed explicitly because the package no longer defaults one; every URL below
    // is unchanged.
    Resources::register(['group_prefix' => 'resources']);

    // The surface under test is the RESOURCE and its op; the group a host mounts it under is the
    // host's business, and running through the session/CSRF stack would only test testbench's wiring.
    // Authorization is still exercised — it rides the op's declared `ability`, not the route group,
    // and the deny path has its own test below.
    test()->withoutMiddleware();
});

function ledgerRow(array $attributes = []): NotificationStatus
{
    return NotificationStatus::query()->create([
        'notification_id' => (string) Str::uuid(),
        'notification_type' => BrandedOverrideNotification::class,
        'channel' => 'mail',
        'notifiable_type' => TeamMemberUser::class,
        'notifiable_id' => (string) Str::uuid(),
        'status' => NotificationDeliveryStatus::Failed,
        'attempts' => 1,
        'last_error' => str_repeat('x', 900),
        'notification' => serialize(new BrandedOverrideNotification),
        ...$attributes,
    ]);
}

/**
 * An operator whose host has granted exactly the two abilities this surface needs: the class-level
 * `view` token the ledger's read guard consults, and the `update` ability the replay op declares.
 *
 * ⚠️ **This was `Gate::before(fn () => true)` until registry-kernel 72, and that one line meant every
 * read assertion in this file measured nothing.** With the gate forced open, `index` could not be
 * observed returning the whole ledger to a caller holding nothing — which is exactly what it did, for
 * as long as the resource had no `scope()`. `AGENTS.md` names this instrument by name: a security
 * trace taken with the gate open measures nothing, and this file was its second instance in the estate.
 *
 * Granting two named abilities instead keeps every assertion below true for the reason it claims. The
 * deny paths (an unauthorized replay; an unprivileged read) are covered in `LedgerReadGuardTest`, which
 * opens no gate at all.
 */
function actAsPermittedOperator(): void
{
    Gate::define(PermissionNamer::assemble(NotificationStatus::class, 'view'), fn () => true);
    Gate::before(function ($user, string $ability) {
        // The replay op's declared `update` ability, granted narrowly rather than universally.
        return $ability === 'update' ? true : null;
    });

    test()->actingAs(new AuthUser);
}

it('serves the ledger as a read-only list, newest first, with the payload projected as a predicate', function () {
    actAsPermittedOperator();

    $older = ledgerRow(['channel' => 'mail']);
    $older->forceFill(['created_at' => now()->subDay()])->save();

    $newer = ledgerRow(['channel' => 'slack', 'notification' => null]);

    $response = $this->getJson('resources/notification-statuses')->assertOk();

    $rows = $response->json('data');

    expect($rows)->toHaveCount(2)
        // #[Sortable(default: true)] on created_at desc is the whole ordering contract of a
        // filterable:false index.
        ->and($rows[0]['id'])->toBe((string) $newer->getKey())
        ->and($rows[1]['id'])->toBe((string) $older->getKey())
        // The predicate, not the payload: a null `notification` IS the package's replayable:false.
        ->and($rows[0]['replayable'])->toBeFalse()
        ->and($rows[1]['replayable'])->toBeTrue()
        ->and($rows[0]['status'])->toBe('failed')
        ->and($rows[0]['channel'])->toBe('slack');

    // Neither the serialized instance nor the 1000-char error travels.
    expect($rows[0])->not->toHaveKey('notification')
        ->and($rows[0])->not->toHaveKey('lastError')
        ->and($rows[0])->not->toHaveKey('last_error');
});

it('serves one row through show', function () {
    actAsPermittedOperator();

    $row = ledgerRow();

    $this->getJson("resources/notification-statuses/{$row->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.id', (string) $row->getKey())
        ->assertJsonPath('data.notificationType', BrandedOverrideNotification::class);
});

it('queues the incumbent replay job by default and answers with the declared output shape', function () {
    actAsPermittedOperator();
    Bus::fake();

    $row = ledgerRow();

    $this->postJson("resources/notification-statuses/{$row->getKey()}/op/replay")
        ->assertOk()
        ->assertJsonPath('data.queued', true)
        ->assertJsonPath('data.id', (string) $row->getKey())
        ->assertJsonPath('data.status', 'failed');

    Bus::assertDispatched(
        ReplayNotificationStatus::class,
        fn (ReplayNotificationStatus $job) => $job->statusId === (string) $row->getKey(),
    );
});

it('runs the replay inline on ?async=false, so the recorder re-arms the same row', function () {
    actAsPermittedOperator();

    // A real send first, so the row carries a serialized instance the recorder itself stored — the
    // shape a replay reconstructs from, not one the test hand-rolled.
    $member = TeamMemberUser::query()->create(['email' => 'ops@site.test']);
    NotificationFacade::send($member, new BrandedOverrideNotification);

    $row = NotificationStatus::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Sent)
        ->and($row->attempts)->toBe(1);

    // Put it where an operator would find it: a failed delivery awaiting a replay.
    $row->forceFill([
        'status' => NotificationDeliveryStatus::Failed,
        'failed_at' => now(),
        'last_error' => 'Connection refused',
    ])->save();

    $this->postJson("resources/notification-statuses/{$row->getKey()}/op/replay?async=false")
        ->assertOk()
        ->assertJsonPath('data.queued', false);

    // The replay preserved the correlation id, so the recorder wrote onto the SAME row rather than
    // forking a second one — one row, one more attempt, back to sent.
    expect(NotificationStatus::query()->count())->toBe(1);

    $replayed = $row->fresh();

    expect($replayed->status)->toBe(NotificationDeliveryStatus::Sent)
        ->and($replayed->attempts)->toBe(2)
        ->and($replayed->last_error)->toBeNull();
});

it('refuses a given_up row, matching the ledger write-once rule', function () {
    actAsPermittedOperator();
    Bus::fake();

    $row = ledgerRow([
        'status' => NotificationDeliveryStatus::GivenUp,
        'given_up_at' => now(),
    ]);

    $this->postJson("resources/notification-statuses/{$row->getKey()}/op/replay")
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    Bus::assertNothingDispatched();
});

it('refuses a sent row on the same terminal predicate', function () {
    actAsPermittedOperator();
    Bus::fake();

    $row = ledgerRow(['status' => NotificationDeliveryStatus::Sent, 'sent_at' => now()]);

    $this->postJson("resources/notification-statuses/{$row->getKey()}/op/replay")
        ->assertStatus(422);

    Bus::assertNothingDispatched();
});

it('refuses a row that stored no serialized instance', function () {
    actAsPermittedOperator();
    Bus::fake();

    $row = ledgerRow(['notification' => null]);

    $this->postJson("resources/notification-statuses/{$row->getKey()}/op/replay")
        ->assertStatus(422)
        ->assertJsonValidationErrors('replayable');

    Bus::assertNothingDispatched();
});

it('honours the retry policy the package\'s own two doors ask', function () {
    actAsPermittedOperator();
    Bus::fake();

    config()->set('notification-status.max_attempts', 3);
    $row = ledgerRow(['attempts' => 3]);

    $this->postJson("resources/notification-statuses/{$row->getKey()}/op/replay")
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');

    Bus::assertNothingDispatched();
});

it('accepts no input, and says so rather than ignoring a body', function () {
    actAsPermittedOperator();
    Bus::fake();

    $row = ledgerRow();

    $this->postJson("resources/notification-statuses/{$row->getKey()}/op/replay", ['status' => 'sent'])
        ->assertStatus(422);

    Bus::assertNothingDispatched();
});

it('is deny-default: a host that granted nothing cannot replay, and cannot see the row either', function () {
    $this->actingAs(new AuthUser);
    Bus::fake();

    $row = ledgerRow();

    // ⚠️ **404, not 403, and the change is a STRENGTHENING rather than a loosening (registry-kernel 72).**
    // This asserted `assertForbidden()` until the ledger grew a read guard. `scope()` is not only the
    // index filter — it is also the SUBJECT-RESOLUTION gate for show/update/destroy, so a caller who
    // has been granted nothing can no longer resolve the row at all and is refused before the op's
    // `update` ability is ever consulted. The row's existence stops leaking on the way past.
    //
    // The test's claim is unchanged and still the point: granted nothing, the replay does not happen.
    $this->postJson("resources/notification-statuses/{$row->getKey()}/op/replay")
        ->assertNotFound();

    Bus::assertNothingDispatched();
});
