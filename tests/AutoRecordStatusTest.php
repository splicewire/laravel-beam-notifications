<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\NotificationStatus\Models\NotificationStatus;
use Rushing\NotificationStatus\NotificationStatusServiceProvider;
use Schemastud\Beam\Notifications\Notifications\BeamNotification;

/**
 * FC-14 consumption (§4): beam-notifications writes ZERO tracking code. The moment a
 * BeamNotification is sent through Laravel's native pipeline, rushing/laravel-notification-status
 * (which subscribes to NotificationSending/Sent/Failed globally) records its status. This test
 * boots that package alongside and proves a row lands with NO beam-side wiring.
 */
beforeEach(function () {
    // Boot the FC-14 package into the already-running app so its global event subscriber is live.
    app()->register(NotificationStatusServiceProvider::class);

    // Its ledger table (publish-only stub in the real package; created inline here).
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
});

it('auto-records delivery status via FC-14 with zero coupling code in beam', function () {
    // NOT faked — the real (array) mail transport sends, so the native events fire.
    fireSubmission(
        notify: ['to' => ['ops@site.test'], 'channels' => ['mail'], 'subject' => 'S', 'template' => 'B'],
        payload: ['name' => 'Ada'],
    );

    $rows = NotificationStatus::query()->get();

    expect($rows)->not->toBeEmpty()
        ->and($rows->first()->notification_type)->toBe(BeamNotification::class)
        ->and($rows->first()->channel)->toBe('mail');
});
