<?php

namespace Splicewire\Beam\Notifications\Tests;

use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Install\InstallStep;

/**
 * The install step is the only mechanism that propagates this arm's substrate to a host — 58 corrected
 * the earlier claim that its stub-migrations audit did (the provider's `stubMigrations()` declaration:
 * it is package-source inspection and never looks at a host).
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
            'beam-notifications-migrations',
            'notification-status-config',
            'notification-status-migrations',
        ], $step->publishTags);
    }

    /**
     * Every tag the step lists must exist, or the install publishes nothing for it (the dead-artifact
     * rot 77 deleted). package-tools registers `<short-name>-migrations` only per `->hasMigrations([...])`
     * entry: attention-surfaces 08 gave this package the stored-notifications table, so its own
     * migrations tag exists again and must publish that stub.
     */
    public function test_the_step_names_its_own_migrations_tag_and_it_publishes_the_notifications_table(): void
    {
        $this->assertContains('beam-notifications-migrations', $this->step()->publishTags);

        $published = \Illuminate\Support\ServiceProvider::pathsToPublish(null, 'beam-notifications-migrations');

        $this->assertCount(1, $published);
        $this->assertStringEndsWith('create_notifications_table.php.stub', array_key_first($published));
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
