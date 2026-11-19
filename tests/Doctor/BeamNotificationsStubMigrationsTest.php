<?php

namespace Splicewire\Beam\Notifications\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;
use Splicewire\Beam\Doctor\Testing\AssertsStubMigrations;
use Splicewire\Beam\Notifications\BeamNotificationsServiceProvider;
use Splicewire\Beam\Notifications\Tests\TestCase;

/**
 * beam-notifications' own operator check: its migrations must stay publish-only .stub files. Mirrors
 * the per-package `DeclaredTopologyTest` shape (`rushing/php-package-topology`'s
 * `AssertsDeclaredTopology`) — a thin test wrapping a shared engine, declaring only "which package is
 * mine."
 */
class BeamNotificationsStubMigrationsTest extends TestCase
{
    use AssertsStubMigrations;

    public function test_beam_notifications_migrations_are_publish_only_stubs(): void
    {
        $this->assertMigrationsArePublishOnlyStubs();
    }

    /**
     * The shared trait only asserts "not Fail", so it would sit green over a warn. Ticket 77 deleted the
     * dead `beam_notifications` outbox and this package shipped no migrations at all; attention-surfaces
     * 08 gave it the stored-notifications table (decision 02 ruling 2), so the audit now reads the
     * "ships publish-only stubs" branch. Pin that status, not just the absence of a failure.
     */
    public function test_the_notifications_table_ships_as_a_registered_publish_only_stub(): void
    {
        $findings = (new StubMigrationsAudit(
            'splicewire/laravel-beam-notifications',
            BeamNotificationsServiceProvider::class,
        ))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('ships publish-only .stub migrations', $findings[0]->detail);
    }

    protected function stubMigrationsPackage(): ?string
    {
        return 'splicewire/laravel-beam-notifications';
    }

    protected function stubMigrationsProvider(): ?string
    {
        return BeamNotificationsServiceProvider::class;
    }
}
