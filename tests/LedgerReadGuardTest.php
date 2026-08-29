<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rushing\NotificationStatus\Enums\NotificationDeliveryStatus;
use Rushing\NotificationStatus\Models\NotificationStatus;
use Rushing\NotificationStatus\NotificationStatusServiceProvider;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Splicewire\Beam\Notifications\Resources;
use Splicewire\Beam\Notifications\Tests\Fixtures\BrandedOverrideNotification;
use Splicewire\Beam\Notifications\Tests\Fixtures\TeamMemberUser;

/**
 * Registry-kernel 72 — the read guard on the ledger's `index`.
 *
 * ⚠️ **NOTHING in this file opens the gate, and that is the entire point.** Its sibling
 * `LedgerSurfaceTest` proved the surface SERVES; it did so under `Gate::before(fn () => true)`, which
 * is precisely the instrument that cannot see an authorization defect. Measured with the gate closed,
 * `index` returned 200 and the whole ledger to a caller holding nothing, while `show` on those same
 * rows returned 403. This file is the detector that was missing.
 *
 * Verified to FAIL against the pre-fix code: without `NotificationStatusData::scope()`, the first test
 * below sees three rows instead of zero.
 */
beforeEach(function () {
    app()->register(NotificationStatusServiceProvider::class);

    Schema::create('notification_statuses', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('notification_id')->index();
        $table->string('notification_type');
        $table->string('channel');
        $table->string('notifiable_type')->nullable();
        $table->string('notifiable_id')->nullable();
        $table->string('status')->default('pending');
        $table->unsignedInteger('attempts')->default(0);
        $table->text('last_error')->nullable();
        $table->longText('notification')->nullable();
        $table->timestamp('queued_at')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->timestamp('failed_at')->nullable();
        $table->timestamp('given_up_at')->nullable();
        $table->timestamps();
    });

    // Mounted as a host does — the package no longer mounts itself (registry-kernel 71). Real `auth`
    // middleware rather than `withoutMiddleware()`: an earlier probe using the latter reported an
    // unauthenticated 200 that was a harness artifact, and the correction is worth keeping structural.
    Resources::register(['group_prefix' => 'resources', 'middleware' => ['auth']]);

    foreach (range(1, 3) as $i) {
        NotificationStatus::query()->create([
            'notification_id' => (string) Str::uuid(),
            'notification_type' => BrandedOverrideNotification::class,
            'channel' => 'mail',
            'notifiable_type' => TeamMemberUser::class,
            'notifiable_id' => (string) Str::uuid(),
            'status' => NotificationDeliveryStatus::Failed,
            'attempts' => 1,
            'last_error' => 'ERROR-DETAIL-AN-UNPRIVILEGED-CALLER-MUST-NOT-READ',
            'notification' => serialize(new BrandedOverrideNotification),
        ]);
    }
});

it('shows an authenticated caller holding no ability NOTHING, rather than the whole ledger', function () {
    $this->actingAs(new AuthUser);

    $response = $this->getJson('resources/notification-statuses')->assertOk();

    expect($response->json('data'))->toBe([]);
    expect($response->getContent())->not->toContain('ERROR-DETAIL-AN-UNPRIVILEGED-CALLER-MUST-NOT-READ');
});

it('shows an operator holding the class view token the whole ledger, so the guard is not merely always-empty', function () {
    Gate::define(PermissionNamer::assemble(NotificationStatus::class, 'view'), fn () => true);

    $this->actingAs(new AuthUser);

    $response = $this->getJson('resources/notification-statuses')->assertOk();

    expect($response->json('data'))->toHaveCount(3);
});

it('refuses an unauthenticated caller at the middleware, before the guard is even consulted', function () {
    $this->getJson('resources/notification-statuses')->assertStatus(401);
});

it('fails CLOSED when no policy is bound for the ledger model', function () {
    // Diverges deliberately from CalendarData/RankTreeData, which call
    // `Gate::getPolicyFor($model)->scopeForUser(...)` with no null check and fatal here.
    Gate::policy(NotificationStatus::class, null);

    $this->actingAs(new AuthUser);

    $response = $this->getJson('resources/notification-statuses')->assertOk();

    expect($response->json('data'))->toBe([]);
});
