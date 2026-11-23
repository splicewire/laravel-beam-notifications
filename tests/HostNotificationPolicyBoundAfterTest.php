<?php

namespace Splicewire\Beam\Notifications\Tests;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Notifications\BeamNotificationsServiceProvider;
use Splicewire\Beam\Notifications\Tests\Fixtures\HostNotificationPolicy;
use Splicewire\Beam\Notifications\Tests\Fixtures\HostNotificationPolicyProvider;

/**
 * The other order from {@see HostNotificationPolicyBindingTest}: the host's provider boots AFTER this package's and
 * binds its own policy explicitly. Together, a host's explicit bind wins in either order.
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
            array_search(BeamNotificationsServiceProvider::class, $providers, true),
            array_search(HostNotificationPolicyProvider::class, $providers, true),
            'the host provider must boot after the package provider for this test to mean anything',
        );

        $this->assertInstanceOf(HostNotificationPolicy::class, Gate::getPolicyFor(DatabaseNotification::class));
    }
}
