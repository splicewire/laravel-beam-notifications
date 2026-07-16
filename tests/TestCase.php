<?php

namespace Schemastud\Beam\Notifications\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Schemastud\Beam\BeamServiceProvider;
use Schemastud\Beam\Notifications\BeamNotificationsServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * beam-notifications boots on TOP of the beam substrate (BeamSubmission trigger). It does
     * NOT load any satellite/relay provider — that absence is the whole point of §3: a headless
     * beam carries no `central` channel. Tests that need the accounts resolver or the `central`
     * channel register a stub explicitly, so the headless default stays honest.
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
     * The beam substrate tables (schema_records + beam_submissions) as publish-only stubs — the
     * test host owns copies, exactly as a single-tenant host would after vendor:publish.
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

        Schema::create('beam_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('schema_record_id')->index();
            $table->uuid('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('source')->nullable();
            $table->string('channel')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }
}
