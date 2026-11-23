<?php

namespace Splicewire\Beam\Notifications\Tests;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Notifications\Authorization\StoredNotificationPolicy;
use Splicewire\Beam\Notifications\BeamNotificationsServiceProvider;
use Splicewire\Beam\Notifications\Tests\Fixtures\HostGuessedNotificationPolicy;
use Splicewire\Beam\Notifications\Tests\Fixtures\HostGuessingProvider;

/**
 * attention-surfaces 29, ruling 2: a host that maps its policies by `Gate::guessPolicyNamesUsing()` instead of an
 * explicit `Gate::policy()` must also win. `Gate::policies()` lists explicit binds only, so a guessed host policy
 * was invisible to the package's bind-if-absent check, and the package's explicit bind then outranked the guess.
 *
 * The host provider boots AFTER the package's here, which is a real host's order: discovered package providers boot
 * before the app's own (`AppServiceProvider`, where a host sets its guesser). That is why the check runs once the
 * application has booted rather than inside the package's own boot. The other order is
 * {@see HostGuessedNotificationPolicyFirstTest}.
 */
class HostGuessedNotificationPolicyTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), HostGuessingProvider::class];
    }

    public function test_a_host_policy_found_by_the_hosts_guesser_wins(): void
    {
        $providers = array_values(array_map(fn ($provider) => $provider::class, $this->app->getProviders(ServiceProvider::class)));
        $this->assertGreaterThan(
            array_search(BeamNotificationsServiceProvider::class, $providers, true),
            array_search(HostGuessingProvider::class, $providers, true),
            'the host provider must boot after the package provider, as AppServiceProvider does in a host',
        );

        $this->assertArrayNotHasKey(DatabaseNotification::class, Gate::policies(), 'the host bound nothing explicitly');
        $this->assertInstanceOf(HostGuessedNotificationPolicy::class, Gate::getPolicyFor(DatabaseNotification::class));
        $this->assertNotInstanceOf(StoredNotificationPolicy::class, Gate::getPolicyFor(DatabaseNotification::class));
    }
}
