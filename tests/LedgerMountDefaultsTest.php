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
    expect(array_keys($columns))->toEqualCanonicalizing(['label', 'channel', 'status', 'attempts', 'queuedAt']);
});

// owner-review-20261102 K-15 (research 15, D-15-a): "Deliveries" alone named neither the ledger's unit (one
// send to one recipient on one channel) nor which kind of delivery, and beam core's webhook history already
// says `HookDeliveries…`. The resource label is the nav entry, the list title and, through tower's
// `ConnectedSiteScopeLabels`, the noun of every connected-site permission label, so it is pinned here.
it('names the ledger notification deliveries, singular and plural, under the unchanged key', function () {
    $resource = app(ParticleResourceRegistry::class)->get('notification-statuses');

    expect($resource->label)->toBe('Notification deliveries')
        ->and($resource->singularLabel)->toBe('Notification delivery')
        ->and($resource->isFramed())->toBeTrue();
});
