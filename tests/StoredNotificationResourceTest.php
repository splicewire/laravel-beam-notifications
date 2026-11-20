<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Data\MessageReferenceData;
use Splicewire\Beam\Localization\Presentation\MessagePresenters;
use Splicewire\Beam\Notifications\Data\StoredNotificationData;
use Splicewire\Beam\Notifications\Particle\Backing\OwnStoredNotificationsBacking;
use Splicewire\Beam\Notifications\Summary\StoredNotificationSummaryProvider;
use Splicewire\Beam\Notifications\Tests\Fixtures\ReaderUser;
use Splicewire\Beam\Notifications\Tests\Fixtures\UnplacedReader;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * attention-surfaces 09: a reader lists their own stored notifications, newest first, and nobody else's.
 *
 * The package half. It declares the `notifications` resource, framed and read-only, backed per request by the
 * notifiable that ticket 08's port resolves for the signed-in actor. Every read here goes through frame's real list and
 * summary routes. The flagship proves the same under its tenant middleware, through the tenant Frame socket, on Postgres.
 */
beforeEach(function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('n', 32)));
    config()->set('frame.middleware', ['web', 'auth']);
    app()->register(FrameServiceProvider::class);

    (require __DIR__.'/../database/migrations/create_notifications_table.php.stub')->up();
    Schema::create('test_users', function ($table) {
        $table->uuid('id')->primary();
        $table->string('email')->nullable();
    });

    $this->ada = ReaderUser::query()->create(['email' => 'ada@site.test']);
    $this->bea = ReaderUser::query()->create(['email' => 'bea@site.test']);
});

/** Store one row for a notifiable, as Laravel's `database` channel stores it. */
function storeNotificationFor(ReaderUser $user, array $data, string $at, ?string $readAt = null): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'attention-surfaces.09.fixture',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode($data),
        'read_at' => $readAt,
        'created_at' => Carbon::parse($at),
        'updated_at' => Carbon::parse($at),
    ]);

    return $id;
}

it('declares a framed, read-only Notifications resource backed per request by the reader\'s own rows', function () {
    $resource = app(ParticleResourceRegistry::class)->get('notifications');

    expect($resource->data)->toBe(StoredNotificationData::class)
        ->and($resource->backing)->toBe(OwnStoredNotificationsBacking::class)
        ->and($resource->label)->toBe('Notifications')
        ->and($resource->isFramed())->toBeTrue()
        ->and($resource->readOnly)->toBeTrue()
        ->and($resource->summaryProvider)->toBe(StoredNotificationSummaryProvider::class);
});

it('lists only the reader\'s own rows, newest first, and a second reader sees none of them', function () {
    $older = storeNotificationFor($this->ada, ['subject' => 'Older'], '2026-11-20 08:00:00');
    $newer = storeNotificationFor($this->ada, ['subject' => 'Newer'], '2026-11-20 09:00:00');
    $beas = storeNotificationFor($this->bea, ['subject' => 'Bea only'], '2026-11-20 10:00:00');

    $this->actingAs($this->ada);
    $ids = collect($this->getJson('/frame/resources/notifications')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$newer, $older]);

    $this->actingAs($this->bea);
    $ids = collect($this->getJson('/frame/resources/notifications')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$beas]);
});

it('renders each row\'s text by the reader rule: a known kind in the reader\'s locale, a stored subject, an unknown kind\'s stored text', function () {
    app(MessagePresenters::class)
        ->adapter('notifications::fixture.reloaded', ['amount' => 'string'])
        ->identity('notification', fn (array $data) => ($data['kind'] ?? null) === 'fixture.reloaded'
            ? MessageReferenceData::of('notifications::fixture.reloaded', 'Reloaded :amount', ['amount' => (string) $data['amount']])
            : null);
    app('translator')->addLines(['fixture.reloaded' => 'Rechargé :amount'], 'fr', 'notifications');
    app('translator')->addLines(['fixture.reloaded' => 'Reloaded :amount'], 'en', 'notifications');

    $known = storeNotificationFor($this->ada, ['kind' => 'fixture.reloaded', 'amount' => '10 €', 'message' => 'Your balance was reloaded.'], '2026-11-20 08:00:00');
    $authored = storeNotificationFor($this->ada, ['subject' => 'Your intake was received', 'body' => 'Thanks.', 'context' => []], '2026-11-20 09:00:00');
    $unknown = storeNotificationFor($this->ada, ['kind' => 'nobody.registered.this', 'message' => 'Stored words, kept as they are.'], '2026-11-20 10:00:00');

    app()->setLocale('fr');
    $this->actingAs($this->ada);
    $rows = collect($this->getJson('/frame/resources/notifications')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$known]['text'])->toBe('Rechargé 10 €')
        ->and($rows[$known]['kind'])->toBe('fixture.reloaded')
        ->and($rows[$authored]['text'])->toBe('Your intake was received')
        ->and($rows[$authored]['kind'])->toBeNull()
        ->and($rows[$unknown]['text'])->toBe('Stored words, kept as they are.')
        ->and($rows[$unknown]['kind'])->toBe('nobody.registered.this');
});

it('exposes each row\'s link as stored, from action_url or a commerce row\'s cta_url, and its unread state', function () {
    $action = storeNotificationFor($this->ada, ['subject' => 'A', 'action_url' => 'https://other.site.test/review/7?x=1'], '2026-11-20 08:00:00');
    $cta = storeNotificationFor($this->ada, ['kind' => 'k', 'message' => 'B', 'cta_url' => 'https://app.site.test/billing/auto-reload'], '2026-11-20 09:00:00', readAt: '2026-11-20 09:30:00');
    $none = storeNotificationFor($this->ada, ['subject' => 'C'], '2026-11-20 10:00:00');

    $this->actingAs($this->ada);
    $rows = collect($this->getJson('/frame/resources/notifications')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$action]['link'])->toBe('https://other.site.test/review/7?x=1')
        ->and($rows[$action]['unread'])->toBeTrue()
        ->and($rows[$cta]['link'])->toBe('https://app.site.test/billing/auto-reload')
        ->and($rows[$cta]['unread'])->toBeFalse()
        ->and($rows[$none]['link'])->toBeNull()
        ->and($rows[$none]['createdAt'])->toStartWith('2026-11-20T10:00:00');
});

it('answers the reader\'s unread count in the summary, counted over the same rows the list reads', function () {
    storeNotificationFor($this->ada, ['subject' => 'one'], '2026-11-20 08:00:00');
    storeNotificationFor($this->ada, ['subject' => 'two'], '2026-11-20 09:00:00');
    storeNotificationFor($this->ada, ['subject' => 'read'], '2026-11-20 10:00:00', readAt: '2026-11-20 10:05:00');
    storeNotificationFor($this->bea, ['subject' => 'not Ada\'s'], '2026-11-20 11:00:00');

    $this->actingAs($this->ada);
    $figures = collect($this->getJson('/frame/resources/notifications/summary')->assertOk()->json('figures'))->keyBy('key');

    expect($figures['unread']['value'])->toBe(2)
        ->and($figures['total']['value'])->toBe(3);
});

it('reads nothing for an actor the port cannot place, never someone else\'s rows', function () {
    storeNotificationFor($this->ada, ['subject' => 'Ada\'s'], '2026-11-20 08:00:00');
    $stranger = UnplacedReader::query()->create(['email' => 'nobody@site.test']);

    $this->actingAs($stranger);

    expect($this->getJson('/frame/resources/notifications')->assertOk()->json('data'))->toBe([]);

    $figures = collect($this->getJson('/frame/resources/notifications/summary')->assertOk()->json('figures'))->keyBy('key');

    expect($figures['unread']['value'])->toBe(0)
        ->and($figures['total']['value'])->toBe(0);
});

it('refuses a guest', function () {
    storeNotificationFor($this->ada, ['subject' => 'Ada\'s'], '2026-11-20 08:00:00');

    expect($this->getJson('/frame/resources/notifications')->status())->toBeIn([401, 403]);
});
