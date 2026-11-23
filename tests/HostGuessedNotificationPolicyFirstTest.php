<?php

namespace Splicewire\Beam\Notifications\Tests;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Notifications\BeamNotificationsServiceProvider;
use Splicewire\Beam\Notifications\Tests\Fixtures\HostGuessedNotificationPolicy;
use Splicewire\Beam\Notifications\Tests\Fixtures\HostGuessingProvider;

/** The guesser of {@see HostGuessedNotificationPolicyTest}, set by a host provider that boots BEFORE the package's. */
class HostGuessedNotificationPolicyFirstTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [HostGuessingProvider::class, ...parent::getPackageProviders($app)];
    }

    public function test_a_host_policy_found_by_a_guesser_set_before_the_package_boots_wins(): void
    {
        $providers = array_values(array_map(fn ($provider) => $provider::class, $this->app->getProviders(ServiceProvider::class)));
        $this->assertLessThan(
            array_search(BeamNotificationsServiceProvider::class, $providers, true),
            array_search(HostGuessingProvider::class, $providers, true),
            'the host provider must boot before the package provider for this test to mean anything',
        );

        $this->assertInstanceOf(HostGuessedNotificationPolicy::class, Gate::getPolicyFor(DatabaseNotification::class));
    }
}
