<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Notifications\BeamNotificationsServiceProvider;

/**
 * TENANT TWIN of the ubiquitous `beam_notifications` outbox (recohere T10). Identical DDL to the
 * central migration one dir up; carries the dup-guard `if (Schema::hasTable(...)) return;` because a
 * host that runs its central + tenant passes into one schema (or re-runs the tenant pass) would
 * otherwise collide on the already-created table. Published into the host's database/migrations/tenant/
 * by {@see BeamNotificationsServiceProvider::bootMigrations()} (native publishesMigrations, verbatim
 * copy). Only central forms notify, so this tenant copy typically stays empty — but a uniform substrate
 * is cheaper than a branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(Beam::table('notifications'))) {
            return;
        }

        Schema::create(Beam::table('notifications'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('submission_id')->nullable()->index();
            $table->string('form_key')->index();
            $table->string('channel')->default('mail');
            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            $table->json('intent')->nullable();
            $table->string('notifier')->nullable();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Beam::table('notifications'));
    }
};
