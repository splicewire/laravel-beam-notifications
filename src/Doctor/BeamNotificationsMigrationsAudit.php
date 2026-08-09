<?php

namespace Splicewire\Beam\Notifications\Doctor;

use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;
use Splicewire\Beam\Notifications\BeamNotificationsServiceProvider;

class BeamNotificationsMigrationsAudit extends StubMigrationsAudit
{
    protected function packageName(): string
    {
        return 'splicewire/laravel-beam-notifications';
    }

    protected function serviceProviderClass(): string
    {
        return BeamNotificationsServiceProvider::class;
    }
}
