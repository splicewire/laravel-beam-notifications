<?php

namespace Splicewire\Beam\Notifications\Tests;

use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Install\InstallStep;

/**
 * The install step is the only mechanism that propagates this arm's substrate to a host — 58 corrected
 * the earlier claim that `BeamNotificationsMigrationsAudit` did (it is package-source inspection and
 * never looks at a host).
 *
 * What makes this worth a test rather than a comment: two of the three tags belong to a DIFFERENT
 * package. `rushing/laravel-notification-status` has never heard of beam, registers its own tags
 * nowhere in the manifest, and its recorder subscribes globally on boot with no `hasTable` guard — so
 * before 77 its table landed only where someone published by hand, and two hosts sent notifications
 * into a missing table. Nothing about the delegation is visible from either package's own file, which
 * is exactly why the wiring needs an assertion.
 */
class InstallStepTest extends TestCase
{
    public function test_the_step_publishes_the_delegated_substrate_alongside_this_packages_own_config(): void
    {
        $step = $this->step();

        $this->assertNotNull($step, 'beam-notifications registered no install step.');
        $this->assertSame([
            'beam-notifications-config',
            'notification-status-config',
            'notification-status-migrations',
        ], $step->publishTags);
    }

    /**
     * `beam-notifications-migrations` must NOT be listed. package-tools registers a
     * `<short-name>-migrations` publish tag only per `->hasMigrations([...])` entry, and 77 deleted this
     * package's only migration, so the tag does not exist — listing it would publish nothing.
     */
    public function test_the_step_names_no_migrations_tag_of_its_own(): void
    {
        $this->assertNotContains('beam-notifications-migrations', $this->step()->publishTags);
    }

    private function step(): ?InstallStep
    {
        foreach (app(BeamInstallManifest::class)->steps() as $step) {
            if ($step->package === 'splicewire/laravel-beam-notifications') {
                return $step;
            }
        }

        return null;
    }
}
