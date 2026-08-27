<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Notifications\Notifications\BeamNotification;
use Splicewire\Beam\Notifications\Recipients\DefaultRecipientResolver;
use Splicewire\Beam\Notifications\Recipients\NoRecipientsForKind;
use Splicewire\Beam\Notifications\Recipients\Recipient;
use Splicewire\Beam\Notifications\Recipients\RecipientKindRegistry;
use Splicewire\Beam\Notifications\Recipients\UnresolvableRecipientKind;
use Splicewire\Beam\Notifications\Tests\Fixtures\TeamMemberRecipientKind;
use Splicewire\Beam\Notifications\Tests\Fixtures\TeamMemberUser;

/**
 * The resolver is a DISPATCHER over registered recipient kinds (beam-facade 100/159), so it is resolved
 * from the container rather than constructed — its collaborators are the registry and the container.
 */
function resolver(): DefaultRecipientResolver
{
    return app(DefaultRecipientResolver::class);
}

it('resolves a literal to: address through the registered `to` kind (mail-only, no accounts)', function () {
    $recipients = resolver()->resolve(['to' => ['ops@site.test']], []);

    expect($recipients)->toHaveCount(1)
        ->and($recipients[0])->toBeInstanceOf(Recipient::class)
        ->and($recipients[0]->isAddress())->toBeTrue()
        ->and($recipients[0]->address)->toBe('ops@site.test');
});

it('accepts a bare string to:, which is how every live schema in the estate spells it', function () {
    expect(resolver()->resolve(['to' => 'hello@site.test'], [])[0]->address)->toBe('hello@site.test');
});

it('interpolates a payload-ref to: address (`{{ payload.email }}`)', function () {
    $recipients = resolver()->resolve(
        ['to' => ['{{ payload.email }}']],
        ['payload' => ['email' => 'ada@site.test']],
    );

    expect($recipients[0]->address)->toBe('ada@site.test');
});

it('sends the generic notification on-demand to a to: address', function () {
    Notification::fake();

    fireRecordPersisted(
        notify: ['to' => ['ops@site.test'], 'subject' => 'Hi', 'template' => 'x'],
    );

    Notification::assertSentOnDemand(BeamNotification::class);
});

it('ignores the message-configuration keys — only `to` / `to_*` are selectors', function () {
    $recipients = resolver()->resolve([
        'to' => ['ops@site.test'],
        'channels' => ['mail'],
        'subject' => 'S',
        'template' => 'B',
        'notification' => 'App\\Notifications\\Whatever',
    ], []);

    expect($recipients)->toHaveCount(1);
});

it('HARD-errors on to_roles when no kind is registered for it (a registry miss, not a hardcoded guard)', function () {
    expect(fn () => resolver()->resolve(['to_roles' => ['admin']], []))
        ->toThrow(UnresolvableRecipientKind::class);
});

it('HARD-errors on to_teams when no kind is registered for it', function () {
    expect(fn () => resolver()->resolve(['to_teams' => ['team-1']], []))
        ->toThrow(UnresolvableRecipientKind::class);
});

it('HARD-errors on ANY unregistered to_* selector, not just the two the spec happens to name', function () {
    // The old guard threw on the literal list ['to_roles','to_teams'] and silently IGNORED anything
    // else, so a host's own selector went nowhere and said nothing.
    expect(fn () => resolver()->resolve(['to_subscribers' => ['weekly']], []))
        ->toThrow(UnresolvableRecipientKind::class);
});

it('names the kinds that ARE registered, so a miss reads as misspelled-or-missing rather than broken', function () {
    expect(fn () => resolver()->resolve(['to_roles' => ['admin']], []))
        ->toThrow(UnresolvableRecipientKind::class, 'Registered: to.');
});

it('throws rather than silently dropping a to: address that interpolates to nothing', function () {
    // The 100 D3 behaviour change: `Interpolator` renders an unknown path empty and never throws, and
    // the resolver used to drop the empty address — so a typo mailed nobody, successfully.
    expect(fn () => resolver()->resolve(['to' => ['{{ payload.emial }}']], ['payload' => ['email' => 'ada@site.test']]))
        ->toThrow(NoRecipientsForKind::class);
});

it('throws when a REGISTERED kind resolves to nobody — the second terminal', function () {
    Schema::create('test_users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('email');
        $table->string('role')->nullable();
        $table->string('team')->nullable();
    });

    config()->set('beam.notifications.recipient_kinds.to_roles', TeamMemberRecipientKind::class);

    expect(fn () => resolver()->resolve(['to_roles' => ['admin']], []))
        ->toThrow(NoRecipientsForKind::class);
});

it('treats an empty selector value as nobody declared, not as a fault', function () {
    expect(resolver()->resolve(['to' => []], []))->toBe([])
        ->and(resolver()->resolve(['to' => ''], []))->toBe([]);
});

it('resolves to_roles / to_teams to notifiable models through kinds appended to the registry', function () {
    Schema::create('test_users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('email');
        $table->string('role')->nullable();
        $table->string('team')->nullable();
    });

    // What beam-accounts' provider does: APPEND to the config key. No rebinding, no class_exists.
    app(RecipientKindRegistry::class)
        ->register('to_roles', TeamMemberRecipientKind::class)
        ->register('to_teams', TeamMemberRecipientKind::class);

    $admin = TeamMemberUser::create(['email' => 'a@site.test', 'role' => 'admin']);
    $member = TeamMemberUser::create(['email' => 'm@site.test', 'team' => 'team-1']);

    Notification::fake();

    fireRecordPersisted(
        notify: [
            'to_roles' => ['admin'],
            'to_teams' => ['team-1'],
            'channels' => ['mail', 'database'],
            'subject' => 'S',
            'template' => 'B',
        ],
    );

    // Both persistent members got the notification (full channels, incl. database inbox reachable)
    Notification::assertSentTo($admin, BeamNotification::class);
    Notification::assertSentTo($member, BeamNotification::class);
});

it('composes address and member recipients from several selectors in one keyword', function () {
    Schema::create('test_users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('email');
        $table->string('role')->nullable();
        $table->string('team')->nullable();
    });

    app(RecipientKindRegistry::class)->register('to_roles', TeamMemberRecipientKind::class);

    TeamMemberUser::create(['email' => 'a@site.test', 'role' => 'admin']);

    $recipients = resolver()->resolve(['to' => ['ops@site.test'], 'to_roles' => ['admin']], []);

    expect($recipients)->toHaveCount(2)
        ->and($recipients[0]->isAddress())->toBeTrue()
        ->and($recipients[1]->isNotifiable())->toBeTrue();
});
