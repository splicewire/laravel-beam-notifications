<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Notifications\Data\ReplayDispatchData;
use Splicewire\Beam\Notifications\Resources;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

it('declares the ledger and replay output at boot without mounting HTTP routes', function () {
    expect(app(ParticleResourceRegistry::class)->has('notification-statuses'))->toBeTrue();
    expect(array_map(fn ($operation) => $operation->output, app(ParticleOperationRegistry::class)->all()))
        ->toContain(ReplayDispatchData::class);

    $routes = array_filter(
        Route::getRoutes()->getRoutes(),
        fn ($route) => str_contains($route->uri(), 'notification-statuses'),
    );
    expect($routes)->toBeEmpty();
});

it('inherits the host prefix and middleware when mounted inside its route group', function (bool $explicitOverrides) {
    $options = [];
    if ($explicitOverrides) {
        // A host can override even a previously published config carrying the old defaults.
        config([
            'beam.notifications.resources.group_prefix' => 'resources',
            'beam.notifications.resources.middleware' => ['web', 'auth'],
        ]);
        $options = ['group_prefix' => '', 'middleware' => []];
    }

    Route::prefix('host')->middleware(['host-auth'])->group(fn () => Resources::register($options));

    $routes = array_filter(
        Route::getRoutes()->getRoutes(),
        fn ($route) => str_starts_with($route->uri(), 'host/'),
    );
    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        expect($route->uri())->toStartWith('host/notification-statuses');
        // The compatibility op alias adds its own marker; the package must add no auth stack.
        expect($route->gatherMiddleware())->toContain('host-auth')->not->toContain('web', 'auth');
    }
})->with(['shipped defaults' => false, 'explicit empty overrides' => true]);

it('declares useful columns for the read-only delivery list', function () {
    $manifest = app(Schemastud\Frame\Registry\ContextManifest::class)->forResource(Splicewire\Beam\Notifications\Data\NotificationStatusData::class);
    $columns = array_filter($manifest['byNode'], fn ($contexts) => $contexts['list-column']['participates'] ?? false);
    expect(array_keys($columns))->toEqualCanonicalizing(['notificationType', 'channel', 'status', 'attempts', 'queuedAt']);
});
