<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Notifications\BeamNotificationsServiceProvider;

/**
 * The notifications outbox — the notify-capability arm's own ledger. Package-owned, ubiquitously
 * provisioned (recohere T10): shipped as a CENTRAL migration here plus a guarded tenant twin at
 * database/migrations/tenant/, both published into the host by
 * {@see BeamNotificationsServiceProvider::bootMigrations()} (native publishesMigrations). This
 * SQUASHES the host-owned `submission_notifications`→`beam_notifications` rename shim (central-only)
 * into one clean greenfield create; it carries the shim's natural timestamp (2026_07_08_100001) so it
 * orders where the shim did.
 *
 * Homed here (research/01 C14 — the notifications half): beam-notifications requires beam-core, so it
 * can route the table name through {@see Beam::table()}. Only genuinely-central forms notify (marketing
 * leads, relay inbound); per-tenant circuit intake records no outbox row (guarded at the delivery
 * seam). Running the same shape in both passes is harmless — the tenant copy simply stays empty.
 */
return new class extends Migration
{
    public function up(): void
    {
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
