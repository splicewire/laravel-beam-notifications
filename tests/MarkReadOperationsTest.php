<?php

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Notifications\Authorization\StoredNotificationPolicy;
use Splicewire\Beam\Notifications\Routing\NotificationsRoutes;
use Splicewire\Beam\Notifications\Tests\Fixtures\ReaderUser;
use Splicewire\Beam\Notifications\Tests\Fixtures\SecondConnectionReader;
use Splicewire\Beam\Notifications\Tests\Fixtures\UnplacedReader;

/**
 * attention-surfaces 10: a reader marks one notification read, or all of theirs, from the framed list.
 *
 * Two declared operations on the `notifications` resource, each with an action affordance and `ability: 'update'`.
 * The row is found through the resource's backing, so another person's row is not found. Both ops ask the package's
 * stored-notification policy, which admits only the notifiable ticket 08's port resolves for the actor (bound only when
 * the host binds none; {@see \Splicewire\Beam\Notifications\Tests\HostNotificationPolicyBindingTest}). Every call here is
 * a real request to the routes the host mounts.
 */
beforeEach(function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('n', 32)));
    config()->set('frame.middleware', ['web', 'auth']);
    config()->set('auth.providers.users.model', ReaderUser::class);
    app()->register(FrameServiceProvider::class);

    // The host's call, inside its own authenticated group (registry-kernel 71: the package adds no middleware).
    Route::middleware(['web', 'auth'])->group(fn () => NotificationsRoutes::mount());
    app('router')->getRoutes()->refreshNameLookups();

    (require __DIR__.'/../database/migrations/create_notifications_table.php.stub')->up();
    Schema::create('test_users', function ($table) {
        $table->uuid('id')->primary();
        $table->string('email')->nullable();
    });

    $this->ada = ReaderUser::query()->create(['email' => 'ada@site.test']);
    $this->bea = ReaderUser::query()->create(['email' => 'bea@site.test']);
});

function storeUnreadFor(ReaderUser $user, string $at = '2026-11-20 08:00:00'): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => 'attention-surfaces.10.fixture',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode(['subject' => 'Hello']),
        'read_at' => null,
        'created_at' => Carbon::parse($at),
        'updated_at' => Carbon::parse($at),
    ]);

    return $id;
}

function readAtOf(string $id): ?string
{
    return DB::table('notifications')->where('id', $id)->value('read_at');
}

it('marks the reader\'s own row read and answers the row as the list shows it', function () {
    $own = storeUnreadFor($this->ada);
    $other = storeUnreadFor($this->ada, '2026-11-20 09:00:00');

    $this->actingAs($this->ada);
    $response = $this->postJson("/beam/notifications/notifications/{$own}/read")->assertOk();

    expect($response->json('data.id'))->toBe($own)
        ->and($response->json('data.unread'))->toBeFalse()
        ->and(readAtOf($own))->not->toBeNull()
        ->and(readAtOf($other))->toBeNull();
});

it('does not find another reader\'s row, and leaves it unread', function () {
    $beas = storeUnreadFor($this->bea);

    $this->actingAs($this->ada);
    $this->postJson("/beam/notifications/notifications/{$beas}/read")->assertNotFound();

    expect(readAtOf($beas))->toBeNull();
});

it('marks all of the reader\'s rows read and nobody else\'s, answering how many it marked', function () {
    $one = storeUnreadFor($this->ada);
    $two = storeUnreadFor($this->ada, '2026-11-20 09:00:00');
    $beas = storeUnreadFor($this->bea);

    $this->actingAs($this->ada);
    $response = $this->postJson('/beam/notifications/notifications/read-all')->assertOk();

    expect($response->json('data.marked'))->toBe(2)
        ->and(readAtOf($one))->not->toBeNull()
        ->and(readAtOf($two))->not->toBeNull()
        ->and(readAtOf($beas))->toBeNull();
});

it('routes the subject-free read-all to its own operation, never to the row route', function () {
    $route = app('router')->getRoutes()->match(Request::create('/beam/notifications/notifications/read-all', 'POST'));

    expect($route->getName())->toBe('beam.notifications.notifications.read-all')
        ->and($route->parameter('id'))->toBeNull();
});

it('refuses an actor the port cannot place, with a coded refusal, and changes nothing', function () {
    $adas = storeUnreadFor($this->ada);
    $this->actingAs(UnplacedReader::query()->create(['email' => 'nobody@site.test']));

    $all = $this->postJson('/beam/notifications/notifications/read-all')->assertForbidden();

    expect($all->json('code'))->toBe('notification_reader_unresolved')
        ->and($all->json('message'))->toBe('There are no notifications here for you to mark read.')
        ->and(readAtOf($adas))->toBeNull();
});

it('refuses a guest as unauthenticated', function () {
    $adas = storeUnreadFor($this->ada);

    $this->postJson("/beam/notifications/notifications/{$adas}/read")->assertUnauthorized();
    $this->postJson('/beam/notifications/notifications/read-all')->assertUnauthorized();

    expect(readAtOf($adas))->toBeNull();
});

it('binds the package\'s stored-notification policy when the host binds none', function () {
    expect(Gate::getPolicyFor(DatabaseNotification::class))->toBeInstanceOf(StoredNotificationPolicy::class);
});

it('declares both operations as actions Frame draws on the notifications list', function () {
    $this->actingAs($this->ada);

    $entry = collect($this->getJson('/frame/manifest')->assertOk()->json('resources'))->firstWhere('key', 'notifications');
    $actions = collect($entry['actions'] ?? [])->keyBy('key');

    expect($actions->keys()->all())->toContain('read', 'read-all')
        ->and($actions['read']['label'])->toBe('Mark read')
        ->and($actions['read-all']['label'])->toBe('Mark all read');
});

it('admits a row only for the person it is addressed to, and refuses an unplaced actor a row', function () {
    $policy = app(StoredNotificationPolicy::class);
    $adas = DatabaseNotification::query()->findOrFail(storeUnreadFor($this->ada));
    $stranger = UnplacedReader::query()->create(['email' => 'nobody@site.test']);

    expect($policy->update($this->ada, $adas))->toBeTrue()
        ->and($policy->update($this->bea, $adas))->toBeFalse()
        ->and($policy->update($stranger, $adas))->toBeFalse()
        ->and($policy->update(null, $adas))->toBeFalse();
});

it('refuses a malformed row id at the route, so it never reaches the uuid column', function () {
    $this->actingAs($this->ada);

    expect(fn () => app('router')->getRoutes()->match(Request::create('/beam/notifications/notifications/not-a-uuid/read', 'POST')))
        ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

    $this->postJson('/beam/notifications/notifications/not-a-uuid/read')->assertNotFound();
});

it('answers 200 for an already-read row and leaves its read_at as it was', function () {
    $id = storeUnreadFor($this->ada);
    DB::table('notifications')->where('id', $id)->update(['read_at' => Carbon::parse('2026-11-19 07:00:00')]);
    $before = readAtOf($id);

    $this->actingAs($this->ada);
    $this->postJson("/beam/notifications/notifications/{$id}/read")->assertOk()->assertJsonPath('data.unread', false);

    expect(readAtOf($id))->toBe($before);
});

it('writes on the reader\'s own connection only: an ambient decoy with the same morph pair stays unread', function () {
    config()->set('database.connections.second', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    Schema::connection('second')->create('test_users', function ($table) {
        $table->uuid('id')->primary();
        $table->string('email')->nullable();
    });
    Schema::connection('second')->create('notifications', function ($table) {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->uuidMorphs('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
    $reader = SecondConnectionReader::query()->create(['email' => 'central@site.test']);
    $row = fn (string $connection) => tap((string) Str::uuid(), fn (string $id) => DB::connection($connection)->table('notifications')->insert([
        'id' => $id, 'type' => 'attention-surfaces.10.fixture', 'notifiable_type' => $reader->getMorphClass(),
        'notifiable_id' => $reader->getKey(), 'data' => json_encode(['subject' => 'x']), 'read_at' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]));
    $one = $row('second');
    $two = $row('second');
    $decoy = $row(config('database.default'));

    $this->actingAs($reader);
    $this->postJson("/beam/notifications/notifications/{$one}/read")->assertOk();
    $this->postJson("/beam/notifications/notifications/{$decoy}/read")->assertNotFound();
    $marked = $this->postJson('/beam/notifications/notifications/read-all')->assertOk()->json('data.marked');

    expect($marked)->toBe(1)
        ->and(DB::connection('second')->table('notifications')->whereNull('read_at')->count())->toBe(0)
        ->and(DB::table('notifications')->where('id', $decoy)->value('read_at'))->toBeNull();
});
