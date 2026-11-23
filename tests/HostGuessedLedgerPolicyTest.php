<?php

namespace Splicewire\Beam\Notifications\Tests;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Rushing\NotificationStatus\Models\NotificationStatus;
use Splicewire\Beam\Notifications\BeamNotificationsServiceProvider;
use Splicewire\Beam\Notifications\Tests\Fixtures\HostGuessedLedgerPolicy;
use Splicewire\Beam\Notifications\Tests\Fixtures\HostGuessingLedgerProvider;

/**
 * attention-surfaces 29: the delivery ledger's policy bind has the same boot-order hazard as the stored-notification
 * policy ({@see HostGuessedNotificationPolicyTest}). A host that maps its ledger policy by its guesser, in a provider
 * that boots after this package's (as `AppServiceProvider` does), must keep it: the package binds its cascade policy
 * only when the Gate has none for the ledger model once the application has booted. Checked inside the package's own
 * boot, the guess is not there yet, and the package's explicit bind then outranks it.
 */
class HostGuessedLedgerPolicyTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), HostGuessingLedgerProvider::class];
    }

    public function test_a_ledger_policy_found_by_the_hosts_guesser_wins(): void
    {
        $providers = array_values(array_map(fn ($provider) => $provider::class, $this->app->getProviders(ServiceProvider::class)));
        $this->assertGreaterThan(
            array_search(BeamNotificationsServiceProvider::class, $providers, true),
            array_search(HostGuessingLedgerProvider::class, $providers, true),
            'the host provider must boot after the package provider, as AppServiceProvider does in a host',
        );

        $this->assertArrayNotHasKey(NotificationStatus::class, Gate::policies(), 'the host bound nothing explicitly');
        $this->assertInstanceOf(HostGuessedLedgerPolicy::class, Gate::getPolicyFor(NotificationStatus::class));
    }
}
