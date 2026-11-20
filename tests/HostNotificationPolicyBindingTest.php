<?php

namespace Splicewire\Beam\Notifications\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Notifications\Authorization\StoredNotificationPolicy;

/**
 * The package binds its stored-notification policy only when the host has bound none (attention-surfaces 10; coord
 * ruling 22:11Z, the same bind-if-absent shape as ticket 08's port). Here the host's provider boots BEFORE this
 * package's and binds its own; the package must leave it in place. A plain `Gate::policy()` in the package would
 * replace it, and this test would fail.
 */
class HostNotificationPolicyBindingTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [HostNotificationPolicyProvider::class, ...parent::getPackageProviders($app)];
    }

    public function test_a_host_policy_bound_before_the_package_boots_survives(): void
    {
        $providers = array_values(array_map(fn ($provider) => $provider::class, $this->app->getProviders(ServiceProvider::class)));

        $this->assertLessThan(
            array_search(\Splicewire\Beam\Notifications\BeamNotificationsServiceProvider::class, $providers, true),
            array_search(HostNotificationPolicyProvider::class, $providers, true),
            'the host provider must boot before the package provider for this test to mean anything',
        );

        $this->assertInstanceOf(HostNotificationPolicy::class, Gate::getPolicyFor(DatabaseNotification::class));
        $this->assertNotInstanceOf(StoredNotificationPolicy::class, Gate::getPolicyFor(DatabaseNotification::class));
    }
}

/**
 * The other order: the host's provider boots AFTER this package's. The package bound its own policy first, and the
 * host's later `Gate::policy()` replaces it. Together with the class above, a host's policy wins in either order.
 */
class HostNotificationPolicyBoundAfterTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), HostNotificationPolicyProvider::class];
    }

    public function test_a_host_policy_bound_after_the_package_boots_wins(): void
    {
        $providers = array_values(array_map(fn ($provider) => $provider::class, $this->app->getProviders(ServiceProvider::class)));

        $this->assertGreaterThan(
            array_search(\Splicewire\Beam\Notifications\BeamNotificationsServiceProvider::class, $providers, true),
            array_search(HostNotificationPolicyProvider::class, $providers, true),
            'the host provider must boot after the package provider for this test to mean anything',
        );

        $this->assertInstanceOf(HostNotificationPolicy::class, Gate::getPolicyFor(DatabaseNotification::class));
    }
}

class HostNotificationPolicy
{
    public function update(?Authenticatable $actor, mixed $notification = null): bool
    {
        return false;
    }
}

class HostNotificationPolicyProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(DatabaseNotification::class, HostNotificationPolicy::class);
    }
}
