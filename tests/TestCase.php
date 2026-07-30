<?php

namespace Splicewire\Beam\Notifications\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Notifications\BeamNotificationsServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * beam-notifications boots on TOP of the beam substrate, listening on the generic
     * {@see BeamParticlePersisted} trigger (ticket 05). It does NOT load any
     * satellite/relay provider — that absence is the whole point of §3: a headless beam carries no
     * `central` channel. Tests that need the accounts resolver or the `central` channel register a stub
     * explicitly, so the headless default stays honest.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BeamServiceProvider::class,
            MediaLibraryServiceProvider::class,
            ActivitylogServiceProvider::class,
            LaravelDataServiceProvider::class,
            BeamNotificationsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('mail.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateBeamTables();
    }

    /**
     * The beam substrate `schema_records` table as a publish-only stub — the test host owns a copy,
     * exactly as a single-tenant host would after vendor:publish. (The retired `beam_submissions` table
     * is gone: ADR-0138 dropped the model and ticket 05 rewired the trigger off it.)
     */
    protected function migrateBeamTables(): void
    {
        Schema::create('schema_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }
}
