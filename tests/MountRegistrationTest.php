<?php

namespace Splicewire\Beam\Notifications\Tests;

use Splicewire\Beam\Notifications\Routing\NotificationsRoutes;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

class MountRegistrationTest extends TestCase
{
    public function test_mount_after_provider_boot_does_not_supersede_resource_declarations(): void
    {
        $registry = app(ParticleResourceRegistry::class);
        $this->assertResourcesRegisteredOnce($registry);

        NotificationsRoutes::mount();

        $this->assertResourcesRegisteredOnce($registry);

        NotificationsRoutes::mount();

        $this->assertResourcesRegisteredOnce($registry);
    }

    public function test_mount_still_declares_resources_when_the_registry_has_no_package_declarations(): void
    {
        $registry = new ParticleResourceRegistry;
        $this->app->instance(ParticleResourceRegistry::class, $registry);
        $this->assertFalse($registry->has('notification-statuses'));

        NotificationsRoutes::mount();

        $this->assertResourcesRegisteredOnce($registry);
    }

    private function assertResourcesRegisteredOnce(ParticleResourceRegistry $registry): void
    {
        foreach (['notification-statuses'] as $key) {
            $this->assertTrue($registry->has($key), $key.' must be registered');
            $this->assertSame([], $registry->superseded($key), $key.' must have no supersession record');
        }
    }
}
