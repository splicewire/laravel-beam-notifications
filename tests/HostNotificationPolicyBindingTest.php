<?php

namespace Splicewire\Beam\Notifications\Tests;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Notifications\Authorization\StoredNotificationPolicy;
use Splicewire\Beam\Notifications\BeamNotificationsServiceProvider;
use Splicewire\Beam\Notifications\Tests\Fixtures\HostNotificationPolicy;
use Splicewire\Beam\Notifications\Tests\Fixtures\HostNotificationPolicyProvider;

/**
 * The package binds its stored-notification policy only when the host has bound none (attention-surfaces 10; coord
 * ruling 22:11Z, the same bind-if-absent shape as ticket 08's port). Here the host's provider boots BEFORE this
 * package's and binds its own; the package must leave it in place. A plain `Gate::policy()` in the package would
 * replace it, and this test would fail. The other order is {@see HostNotificationPolicyBoundAfterTest}.
 *
 * One test class per file (attention-surfaces 29): PHPUnit loads one class from a file, so while this class shared a
 * file with the other order, only that one ran.
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
            array_search(BeamNotificationsServiceProvider::class, $providers, true),
            array_search(HostNotificationPolicyProvider::class, $providers, true),
            'the host provider must boot before the package provider for this test to mean anything',
        );

        $this->assertInstanceOf(HostNotificationPolicy::class, Gate::getPolicyFor(DatabaseNotification::class));
        $this->assertNotInstanceOf(StoredNotificationPolicy::class, Gate::getPolicyFor(DatabaseNotification::class));
    }
}
