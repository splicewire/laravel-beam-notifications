<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Notifications\Support\NotificationDispatcher;
use Splicewire\Beam\Notifications\Tests\Fixtures\ReplicaMemberRecipientKind;
use Splicewire\Beam\Notifications\Tests\Fixtures\TeamMemberUser;
use Splicewire\Beam\Notifications\Tests\Fixtures\TenantReplicaUser;

/**
 * AS11 (third face): a `to_*` recipient KIND under a tenant guard returns `Recipient::notifiable($replica)`,
 * where the replica is a TenantUser-shaped model that is NOT notifiable and whose notification rows belong
 * to the central person behind it (attention-surfaces decision 02, ruling 3). NotificationDispatcher::send()
 * must map every notifiable recipient through the {@see \Splicewire\Beam\Notifications\Contracts\NotifiableForActor}
 * port before `Notification::send`, exactly as MarkAllNotificationsReadOp already does — so the CENTRAL person
 * is notified, never the bare replica (which is non-notifiable: dispatching to it throws / loses the send).
 *
 * The port itself is proven in StoredNotificationTest; THIS asserts the dispatcher ROUTES THROUGH it. The
 * dispatcher is driven directly (like RecipientResolverTest drives the resolver), over REAL array mail
 * transport (mail.default=array) — never Notification::fake, which hides both the replica throw and a mis-send.
 */
beforeEach(function () {
    // BeamNotification is ShouldQueue; the sync connection runs it inline so the real transport captures it.
    config()->set('queue.default', 'sync');

    Schema::create('test_users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('email')->nullable();
    });

    Schema::create('test_replica_users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('email')->nullable();
        $table->string('role')->nullable();
    });

    TenantReplicaUser::$centralModel = TeamMemberUser::class;
});

afterEach(function () {
    TenantReplicaUser::$centralModel = TeamMemberUser::class;
});

/**
 * @return list<string> every envelope recipient address across all messages the array transport captured
 */
function dispatchedMailRecipients(): array
{
    $addresses = [];

    foreach (app('mailer')->getSymfonyTransport()->messages() as $sent) {
        foreach ($sent->getEnvelope()->getRecipients() as $address) {
            $addresses[] = $address->getAddress();
        }
    }

    return $addresses;
}

it('notifies the central person behind a tenant-replica recipient, not the bare replica', function () {
    $central = TeamMemberUser::query()->create(['email' => 'central@site.test']);

    // The replica carries the central's global identifier (its own id) and a DIFFERENT address, so the
    // assertion proves the mail went to the mapped CENTRAL, not to whatever address the replica carried.
    TenantReplicaUser::query()->create([
        'id' => $central->getKey(),
        'email' => 'replica@site.test',
        'role' => 'admin',
    ]);

    config()->set('beam.notifications.recipient-kinds.to_roles', ReplicaMemberRecipientKind::class);

    app(NotificationDispatcher::class)->dispatch(
        ['to_roles' => ['admin'], 'channels' => ['mail'], 'subject' => 'S', 'template' => 'B'],
        ['payload' => [], 'schema' => []],
        ['mail'],
    );

    $recipients = dispatchedMailRecipients();

    expect($recipients)->toContain('central@site.test')
        ->and($recipients)->not->toContain('replica@site.test');
});

it('skips a replica that maps to nobody without throwing, and sends nothing', function () {
    // A replica whose central row does not exist resolves to nobody (port rule 3). The dispatcher must
    // report-and-skip, never throw on a lost recipient, and never fall through to the table or the replica.
    TenantReplicaUser::query()->create([
        'id' => (string) Str::uuid(),
        'email' => 'orphan-replica@site.test',
        'role' => 'admin',
    ]);
    // deliberately NO matching TeamMemberUser central row

    config()->set('beam.notifications.recipient-kinds.to_roles', ReplicaMemberRecipientKind::class);

    app(NotificationDispatcher::class)->dispatch(
        ['to_roles' => ['admin'], 'channels' => ['mail'], 'subject' => 'S', 'template' => 'B'],
        ['payload' => [], 'schema' => []],
        ['mail'],
    );

    expect(dispatchedMailRecipients())->toBe([]);
});
