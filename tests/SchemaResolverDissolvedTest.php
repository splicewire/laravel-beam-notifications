<?php

use Splicewire\Beam\Notifications\Listeners\NotifyOnSubmission;
use Splicewire\Beam\Notifications\Support\RegistrySchemaResolver;

/**
 * beam-facade ticket 40 dissolved the one-implementation `SchemaResolver` interface and deleted the
 * superseded `SnapshotSchemaResolver`. Asserted rather than grepped once (ticket 32's improvement on
 * 18): a stale import of either class must fatal, and this is the gate that keeps saying so.
 */
it('has no SchemaResolver interface and no SnapshotSchemaResolver', function () {
    expect(interface_exists('Splicewire\Beam\Notifications\Contracts\SchemaResolver'))->toBeFalse()
        ->and(class_exists('Splicewire\Beam\Notifications\Support\SnapshotSchemaResolver'))->toBeFalse();
});

it('autowires the concrete resolver into the listener', function () {
    $listener = app(NotifyOnSubmission::class);

    $resolver = (new ReflectionClass($listener))
        ->getProperty('schemaResolver');

    expect($resolver->getType()?->getName())->toBe(RegistrySchemaResolver::class)
        ->and($resolver->getValue($listener))->toBeInstanceOf(RegistrySchemaResolver::class);
});
