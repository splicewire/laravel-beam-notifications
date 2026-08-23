<?php

namespace Splicewire\Beam\Notifications\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\Testing\AssertsStubMigrations;
use Splicewire\Beam\Notifications\Doctor\BeamNotificationsMigrationsAudit;
use Splicewire\Beam\Notifications\Tests\TestCase;

/**
 * beam-notifications' own operator check: its migrations must stay publish-only .stub files. Mirrors
 * the per-package `DeclaredTopologyTest` shape (`rushing/php-package-topology`'s
 * `AssertsDeclaredTopology`) — a thin test wrapping a shared engine, declaring only "which audit is
 * mine."
 */
class BeamNotificationsMigrationsAuditTest extends TestCase
{
    use AssertsStubMigrations;

    public function test_beam_notifications_migrations_are_publish_only_stubs(): void
    {
        $this->assertMigrationsArePublishOnlyStubs();
    }

    /**
     * The shared trait only asserts "not Fail", so it would sit green over a warn. This package is the
     * family's first to ship NO migrations at all (ticket 77 deleted the dead `beam_notifications`
     * outbox, and durability lives in `rushing/laravel-notification-status`), which is the case ticket
     * 77 taught {@see \Splicewire\Beam\Doctor\Support\StubMigrationsAudit} to PASS rather than warn —
     * so pin the status, not just the absence of a failure.
     */
    public function test_shipping_no_migrations_at_all_is_a_pass_and_not_a_warn(): void
    {
        $findings = (new BeamNotificationsMigrationsAudit)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('ships no migrations of its own', $findings[0]->detail);
    }

    protected function stubMigrationsAuditClass(): string
    {
        return BeamNotificationsMigrationsAudit::class;
    }
}
