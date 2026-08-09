<?php

namespace Splicewire\Beam\Notifications\Tests\Doctor;

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

    protected function stubMigrationsAuditClass(): string
    {
        return BeamNotificationsMigrationsAudit::class;
    }
}
