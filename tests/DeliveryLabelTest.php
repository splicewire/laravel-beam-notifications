<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rushing\NotificationStatus\Enums\NotificationDeliveryStatus;
use Rushing\NotificationStatus\Models\NotificationStatus;
use Rushing\NotificationStatus\NotificationStatusServiceProvider;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Splicewire\Beam\Notifications\Notifications\BeamNotification;
use Splicewire\Beam\Notifications\Resources;
use Splicewire\Beam\Notifications\Tests\Fixtures\BrandedOverrideNotification;
use Splicewire\Beam\Notifications\Tests\Fixtures\WakeupTripwire;

/**
 * Owner re-walk WS-08: the Deliveries list named a lead's notification
 * `Splicewire\Beam\Notifications\Notifications\BeamNotification`. Every row now carries a `label` in
 * words, and the list's "Notification" column reads it. Asserted through the served list, the same
 * door the operator's screen reads.
 */
beforeEach(function () {
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

    Resources::register(['group_prefix' => 'resources']);
    test()->withoutMiddleware();

    // The class-level read token the ledger's guard consults, granted narrowly (see LedgerSurfaceTest).
    Gate::define(PermissionNamer::assemble(NotificationStatus::class, 'view'), fn () => true);
    test()->actingAs(new AuthUser);

    WakeupTripwire::$woken = false;
});

function labelledDelivery(string $type, ?string $serialized): NotificationStatus
{
    return NotificationStatus::query()->create([
        'notification_id' => (string) Str::uuid(),
        'notification_type' => $type,
        'channel' => 'mail',
        'status' => NotificationDeliveryStatus::Sent,
        'attempts' => 1,
        'notification' => $serialized,
    ]);
}

/**
 * @return list<string>
 */
function servedDeliveryLabels(): array
{
    return array_column(test()->getJson('resources/notification-statuses')->assertOk()->json('data'), 'label');
}

it('names a form notification by the subject it declared, rendered as the recipient read it', function () {
    fireRecordPersisted(
        notify: ['to' => ['ops@site.test'], 'channels' => ['mail'], 'subject' => 'New access request from {{ payload.name }}'],
        payload: ['name' => 'Ada Lovelace'],
    );

    $row = test()->getJson('resources/notification-statuses')->assertOk()->json('data.0');

    expect($row['label'])->toBe('New access request from Ada Lovelace')
        // The class stays on the wire for tooling; the list reads the label.
        ->and($row['notificationType'])->toBe(BeamNotification::class);
});

it('names a form notification without a declared subject by the form it came from', function () {
    fireRecordPersisted(notify: ['to' => ['ops@site.test'], 'channels' => ['mail']], payload: ['name' => 'Ada']);

    expect(servedDeliveryLabels())->toBe(['New submission: Contact']);
});

it('keeps the mail\'s own default subject when the row stored no instance to read', function () {
    labelledDelivery(BeamNotification::class, null);

    expect(servedDeliveryLabels())->toBe(['New submission']);
});

it('names any other notification by its short name in words, never by its namespace', function (string $type, string $expected) {
    labelledDelivery($type, null);

    $labels = servedDeliveryLabels();

    expect($labels)->toBe([$expected])
        ->and($labels[0])->not->toContain('\\');
})->with([
    'a host override' => [BrandedOverrideNotification::class, 'Branded override'],
    'a framework notification' => ['Illuminate\\Auth\\Notifications\\ResetPassword', 'Reset password'],
    'an acronym survives' => ['App\\Notifications\\SMSAlertNotification', 'SMS alert'],
    'a class that no longer exists' => ['App\\Notifications\\Gone\\InvoicePaid', 'Invoice paid'],
]);

it('serves the same label through show', function () {
    $row = labelledDelivery(BrandedOverrideNotification::class, serialize(new BrandedOverrideNotification));

    test()->getJson("resources/notification-statuses/{$row->getKey()}")
        ->assertOk()
        ->assertJsonPath('data.label', 'Branded override');
});

it('cuts a long declared subject rather than stretching the list', function () {
    $subject = str_repeat('Quarterly pipeline review ', 12);

    labelledDelivery(BeamNotification::class, serialize(new BeamNotification(['mail'], $subject, null, [])));

    $label = servedDeliveryLabels()[0];

    expect(mb_strlen($label))->toBeLessThanOrEqual(120)
        ->and($label)->toStartWith('Quarterly pipeline review')
        ->and($label)->toEndWith('…');
});

it('reads the stored instance without waking anything it carries', function () {
    $stored = new BeamNotification(['mail'], 'Hello {{ payload.name }}', null, [
        'payload' => ['name' => 'Ada', 'tripwire' => new WakeupTripwire],
    ]);

    $blob = serialize($stored);

    // Control: an ordinary unserialize of the same blob DOES wake it, so the last assertion can fail.
    unserialize($blob);
    expect(WakeupTripwire::$woken)->toBeTrue();
    WakeupTripwire::$woken = false;

    labelledDelivery(BeamNotification::class, $blob);

    expect(servedDeliveryLabels())->toBe(['Hello Ada'])
        ->and(WakeupTripwire::$woken)->toBeFalse();
});

it('falls back to the default rather than failing on a blob that will not parse', function () {
    labelledDelivery(BeamNotification::class, 'O:999:"not a notification');

    expect(servedDeliveryLabels())->toBe(['New submission']);
});
