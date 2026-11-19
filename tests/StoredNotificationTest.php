<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Notifications\Contracts\NotifiableForActor;
use Splicewire\Beam\Notifications\Recipients\DefaultNotifiableForActor;
use Splicewire\Beam\Notifications\Tests\Fixtures\PlainActor;
use Splicewire\Beam\Notifications\Tests\Fixtures\StoredFixtureNotification;
use Splicewire\Beam\Notifications\Tests\Fixtures\TeamMemberUser;
use Splicewire\Beam\Notifications\Tests\Fixtures\TenantReplicaUser;

/**
 * attention-surfaces 08: a stored notification is written, and read back for the person it was addressed to.
 *
 * Decision 02 (ruling 2) gives this package the `notifications` table's migration, central-only with a UUID
 * recipient key, and (ruling 3) a port, "the notifiable for this actor", with a default that needs no other
 * package. These are the package half; the flagship proves the same under its real tenant middleware on Postgres.
 */
function notificationsTableStub(): string
{
    return __DIR__.'/../database/migrations/create_notifications_table.php.stub';
}

beforeEach(function () {
    (require notificationsTableStub())->up();

    Schema::create('test_users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('email')->nullable();
    });

    Schema::create('test_replica_users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('email')->nullable();
    });

    TenantReplicaUser::$centralModel = TeamMemberUser::class;
});

afterEach(function () {
    TenantReplicaUser::$centralModel = TeamMemberUser::class;
});

it('ships the notifications table as a publish-only stub that says it is central-only', function () {
    expect(notificationsTableStub())->toBeFile();

    $source = file_get_contents(notificationsTableStub());

    expect($source)->toContain('central-only')
        ->and($source)->toContain("uuidMorphs('notifiable')")
        ->and($source)->not->toContain("->morphs('notifiable')");
});

it('registers the stub so vendor:publish has a beam-notifications-migrations tag', function () {
    $published = ServiceProvider::pathsToPublish(null, 'beam-notifications-migrations');

    expect($published)->not->toBeEmpty()
        ->and(array_keys($published)[0])->toEndWith('create_notifications_table.php.stub');
});

it('keys the recipient by UUID, not by an integer', function () {
    $column = collect(Schema::getColumns('notifications'))->firstWhere('name', 'notifiable_id');

    expect($column)->not->toBeNull()
        ->and(strtolower($column['type_name']))->not->toContain('int');
});

it('stores one row for a real send to a UUID-keyed person, with no notification fake', function () {
    $person = TeamMemberUser::query()->create(['email' => 'ada@site.test']);

    $person->notify(new StoredFixtureNotification);

    $rows = DB::table('notifications')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->notifiable_type)->toBe($person->getMorphClass())
        ->and($rows[0]->notifiable_id)->toBe($person->getKey())
        ->and($rows[0]->type)->toBe(StoredFixtureNotification::class)
        ->and($person->notifications()->count())->toBe(1)
        ->and($person->unreadNotifications()->first()->data)->toBe([
            'kind' => 'fixture.stored',
            'message' => 'notifications::fixture.stored',
        ]);
});

it('binds the default port, and a host binding wins', function () {
    expect(app(NotifiableForActor::class))->toBeInstanceOf(DefaultNotifiableForActor::class);

    $own = new class implements NotifiableForActor
    {
        public function resolve(mixed $actor): ?Model
        {
            return null;
        }
    };

    app()->instance(NotifiableForActor::class, $own);

    expect(app(NotifiableForActor::class))->toBe($own);
});

it('returns an actor that is itself notifiable', function () {
    $person = TeamMemberUser::query()->create(['email' => 'ada@site.test']);

    expect(app(NotifiableForActor::class)->resolve($person))->toBe($person);
});

it('resolves a tenant-side replica to the central person by its global identifier', function () {
    $central = TeamMemberUser::query()->create(['email' => 'ada@site.test']);
    TeamMemberUser::query()->create(['email' => 'someone-else@site.test']);
    $replica = TenantReplicaUser::query()->create(['id' => $central->getKey(), 'email' => 'ada@site.test']);

    $resolved = app(NotifiableForActor::class)->resolve($replica);

    expect($resolved)->toBeInstanceOf(TeamMemberUser::class)
        ->and($resolved->getMorphClass())->toBe($central->getMorphClass())
        ->and($resolved->getKey())->toBe($central->getKey());

    $resolved->notify(new StoredFixtureNotification);

    expect(DB::table('notifications')->where('notifiable_id', $central->getKey())->count())->toBe(1);
});

it('resolves a replica with no central row to nobody, never to another person', function () {
    TeamMemberUser::query()->create(['email' => 'someone-else@site.test']);
    $orphan = TenantReplicaUser::query()->create(['email' => 'gone@site.test']);

    expect(app(NotifiableForActor::class)->resolve($orphan))->toBeNull();
});

it('resolves a replica whose central model is not notifiable to nobody', function () {
    TenantReplicaUser::$centralModel = PlainActor::class;
    $central = PlainActor::query()->create(['email' => 'ada@site.test']);
    $replica = TenantReplicaUser::query()->create(['id' => $central->getKey(), 'email' => 'ada@site.test']);

    expect(app(NotifiableForActor::class)->resolve($replica))->toBeNull();
});

it('resolves anything else to nobody', function (mixed $actor) {
    TeamMemberUser::query()->create(['email' => 'someone-else@site.test']);

    expect(app(NotifiableForActor::class)->resolve(value($actor)))->toBeNull();
})->with([
    'no actor' => [null],
    'a plain model' => [fn () => PlainActor::query()->create(['email' => 'plain@site.test'])],
    'not a model' => [fn () => new stdClass],
]);

it('stores nothing when the port resolves nobody', function () {
    $resolved = app(NotifiableForActor::class)->resolve(new stdClass);
    $resolved?->notify(new StoredFixtureNotification);

    expect(DB::table('notifications')->count())->toBe(0);
});
