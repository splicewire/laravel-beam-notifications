<?php

namespace Splicewire\Beam\Notifications\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Notifications\Contracts\NotifiableForActor;
use Splicewire\Beam\Notifications\Recipients\DefaultNotifiableForActor;

/**
 * A host or the tenancy tier may bind its own "notifiable for this actor" (decision 02 ruling 3), and the package
 * must not overwrite it, whichever provider registers first.
 *
 * Here the host's provider registers BEFORE this package's: the package's `packageRegistered()` then runs over an
 * existing binding. Its `bindIf` leaves the host's resolver in place; a plain `bind` would replace it with the
 * default, and this test would fail.
 */
class HostNotifiableForActorBindingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [HostNotifiableForActorProvider::class, ...parent::getPackageProviders($app)];
    }

    public function test_a_host_binding_made_before_the_package_registers_survives(): void
    {
        // getProviders() is keyed by class name: take the registration order as positions.
        $providers = array_values(array_map(fn ($provider) => $provider::class, $this->app->getProviders(ServiceProvider::class)));

        $this->assertLessThan(
            array_search(\Splicewire\Beam\Notifications\BeamNotificationsServiceProvider::class, $providers, true),
            array_search(HostNotifiableForActorProvider::class, $providers, true),
            'the host provider must register before the package provider for this test to mean anything',
        );

        $resolved = $this->app->make(NotifiableForActor::class);

        $this->assertInstanceOf(HostNotifiableForActor::class, $resolved);
        $this->assertNotInstanceOf(DefaultNotifiableForActor::class, $resolved);
    }
}

class HostNotifiableForActor implements NotifiableForActor
{
    public function resolve(mixed $actor): ?Model
    {
        return null;
    }
}

class HostNotifiableForActorProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotifiableForActor::class, HostNotifiableForActor::class);
    }
}
