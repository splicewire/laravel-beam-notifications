<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Schemastud\Beam\Notifications\Contracts\RecipientResolver;
use Schemastud\Beam\Notifications\Notifications\BeamNotification;
use Schemastud\Beam\Notifications\Recipients\DefaultRecipientResolver;
use Schemastud\Beam\Notifications\Recipients\Recipient;
use Schemastud\Beam\Notifications\Recipients\UnresolvableRecipientKind;
use Schemastud\Beam\Notifications\Tests\Fixtures\StubAccountsRecipientResolver;
use Schemastud\Beam\Notifications\Tests\Fixtures\TeamMemberUser;

it('resolves a literal to: address via the built-in default (mail-only, no accounts)', function () {
    $recipients = (new DefaultRecipientResolver)->resolve(
        ['to' => ['ops@site.test']],
        [],
    );

    expect($recipients)->toHaveCount(1)
        ->and($recipients[0])->toBeInstanceOf(Recipient::class)
        ->and($recipients[0]->isAddress())->toBeTrue()
        ->and($recipients[0]->address)->toBe('ops@site.test');
});

it('interpolates a payload-ref to: address (`{{ payload.email }}`)', function () {
    $recipients = (new DefaultRecipientResolver)->resolve(
        ['to' => ['{{ payload.email }}']],
        ['payload' => ['email' => 'ada@site.test']],
    );

    expect($recipients[0]->address)->toBe('ada@site.test');
});

it('sends the generic notification on-demand to a to: address', function () {
    Notification::fake();

    fireSubmission(
        notify: ['to' => ['ops@site.test'], 'subject' => 'Hi', 'template' => 'x'],
    );

    Notification::assertSentOnDemand(BeamNotification::class);
});

it('HARD-errors on to_roles when no accounts-aware resolver is bound (no silent drop)', function () {
    expect(fn () => (new DefaultRecipientResolver)->resolve(['to_roles' => ['admin']], []))
        ->toThrow(UnresolvableRecipientKind::class);
});

it('HARD-errors on to_teams when no accounts-aware resolver is bound', function () {
    expect(fn () => (new DefaultRecipientResolver)->resolve(['to_teams' => ['team-1']], []))
        ->toThrow(UnresolvableRecipientKind::class);
});

it('resolves to_roles / to_teams through a bound accounts-aware resolver to notifiable models', function () {
    Schema::create('test_users', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('email');
        $table->string('role')->nullable();
        $table->string('team')->nullable();
    });

    // beam-accounts (here: the stub) REBINDS the resolver — the soft-dep integration point.
    app()->bind(RecipientResolver::class, StubAccountsRecipientResolver::class);

    $admin = TeamMemberUser::create(['email' => 'a@site.test', 'role' => 'admin']);
    $member = TeamMemberUser::create(['email' => 'm@site.test', 'team' => 'team-1']);

    Notification::fake();

    fireSubmission(
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
