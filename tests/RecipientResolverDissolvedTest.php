<?php

use Splicewire\Beam\Notifications\Recipients\DefaultRecipientResolver;
use Splicewire\Beam\Notifications\Recipients\Kinds\AddressRecipientKind;
use Splicewire\Beam\Notifications\Recipients\RecipientKindRegistry;
use Splicewire\Beam\Notifications\Support\NotificationDispatcher;

/**
 * beam-facade 100 dissolved the rebindable `RecipientResolver` port, and 159 built the recipient-KIND
 * registry in its place. Asserted rather than grepped once (the same gate SchemaResolverDissolvedTest
 * keeps on ticket 40's dissolution, one seam over): the port existed for a rebinding that was never
 * written in two years, and a stale `implements RecipientResolver` anywhere must fatal rather than
 * quietly reintroduce the shape.
 */
it('has no RecipientResolver port and no stub accounts resolver', function () {
    expect(interface_exists('Splicewire\Beam\Notifications\Contracts\RecipientResolver'))->toBeFalse()
        ->and(class_exists('Splicewire\Beam\Notifications\Tests\Fixtures\StubAccountsRecipientResolver'))->toBeFalse();
});

it('autowires the concrete dispatcher into the notification dispatcher', function () {
    $property = (new ReflectionClass(NotificationDispatcher::class))->getProperty('resolver');

    expect($property->getType()?->getName())->toBe(DefaultRecipientResolver::class)
        ->and($property->getValue(app(NotificationDispatcher::class)))->toBeInstanceOf(DefaultRecipientResolver::class);
});

it('registers the `to` kind and only that one out of the box', function () {
    expect(app(RecipientKindRegistry::class)->declaredKeys())->toBe(['to'])
        ->and(app(RecipientKindRegistry::class)->resolve('to'))->toBe(AddressRecipientKind::class);
});

it('holds the registry as a singleton that still sees a LATE registration', function () {
    // The property that lets beam-accounts append from a packageBooted() running after this package's:
    // ConfigRegistry reads through to the config repository rather than snapshotting at construction.
    $registry = app(RecipientKindRegistry::class);

    config()->set('beam.notifications.recipient_kinds.to_roles', AddressRecipientKind::class);

    expect($registry->has('to_roles'))->toBeTrue()
        ->and(app(RecipientKindRegistry::class))->toBe($registry);
});
