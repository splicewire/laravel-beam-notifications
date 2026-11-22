<?php

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Notifications\Resources;
use Splicewire\Beam\Notifications\Routing\NotificationsRoutes;

// owner-review-20261112 13d (ADR-0243): the package mounts at its own root, beam/notifications, beside the mount the
// host makes today. The host's group is the boundary; legacy routes keep their URIs and names until ticket 16.

/** method + path-under-root => action, for every route whose URI starts with $root; sorted. */
function notificationsRouteTable(string $root): array
{
    $table = [];
    foreach (app(Router::class)->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), $root)) {
            continue;
        }
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $table[$method.' '.substr($route->uri(), strlen($root))] = $route->getActionName();
        }
    }
    ksort($table);

    return $table;
}

/**
 * attention-surfaces 10: the reader's mark-read operations mount at the package root ONLY; the legacy mount keeps
 * serving the ledger alone until ticket 16 retires it. So the root is the legacy table plus exactly these two routes.
 */
function readerOperationRoutes(): array
{
    return [
        'POST notifications/read-all' => 'Splicewire\\Beam\\Http\\Particle\\ParticleOperationController@invoke',
        'POST notifications/{id}/read' => 'Splicewire\\Beam\\Http\\Particle\\ParticleOperationController@invoke',
    ];
}

/** The package root's table without the reader's operations, which the legacy mount deliberately does not carry. */
function legacyShapedTable(string $root): array
{
    return array_diff_key(notificationsRouteTable($root), readerOperationRoutes());
}

it('registers the default root beside the legacy mount, inside the host boundary', function () {
    Route::prefix('host')->group(fn () => Resources::register());
    Route::domain('workspace.example')->middleware('auth')->group(fn () => NotificationsRoutes::mount());

    $current = app(Router::class)->getRoutes()->match(Request::create('http://workspace.example/beam/notifications/notification-statuses'));

    expect(legacyShapedTable('beam/notifications/'))->toBe(notificationsRouteTable('host/'))
        ->and(array_intersect_key(notificationsRouteTable('beam/notifications/'), readerOperationRoutes()))->toBe(readerOperationRoutes())
        ->and(notificationsRouteTable('beam/notifications/'))->not->toBeEmpty()
        ->and($current->gatherMiddleware())->toContain('auth')
        ->and($current->getDomain())->toBe('workspace.example')
        ->and($current->getName())->toStartWith('beam.notifications.');

    $this->getJson('http://workspace.example/beam/notifications/notification-statuses')->assertUnauthorized();
});

it('moves only the package root when the host overrides it', function () {
    Route::prefix('host')->group(fn () => Resources::register());
    config(['beam.notifications.api_root' => '/custom/notify/']);

    NotificationsRoutes::mount();

    expect(legacyShapedTable('custom/notify/'))->toBe(notificationsRouteTable('host/'))
        ->and(array_intersect_key(notificationsRouteTable('custom/notify/'), readerOperationRoutes()))->toBe(readerOperationRoutes())
        ->and(notificationsRouteTable('beam/notifications/'))->toBeEmpty();
});

// attention-surfaces 31: a host that seats the delivery ledger in a second (operator) group mounts the LEDGER ONLY, so
// the reader's mark-read operations exist once, inside the tenant boundary, and their route names keep generating
// tenant URLs. Mounting the whole package twice registered the reader names again last, so the tenant manifest's
// actions pointed at the operator API.
it('mounts the ledger alone for a second group, so the reader operations keep their tenant URLs', function () {
    Route::domain('workspace.example')->middleware('auth')->group(fn () => NotificationsRoutes::mount());
    Route::prefix('api/operator')->middleware('auth')->group(fn () => NotificationsRoutes::mountLedger());
    app('router')->getRoutes()->refreshNameLookups();

    $operator = notificationsRouteTable('api/operator/beam/notifications/');

    expect($operator)->not->toBeEmpty()
        ->and(array_intersect_key($operator, readerOperationRoutes()))->toBe([])
        ->and($operator)->toBe(legacyShapedTable('beam/notifications/'));

    $readerNames = collect(app(Router::class)->getRoutes())
        ->filter(fn ($route) => str_ends_with($route->uri(), 'notifications/read-all') || str_ends_with($route->uri(), 'notifications/{id}/read'))
        ->map(fn ($route) => $route->getName())
        ->unique()
        ->values();

    expect($readerNames)->toHaveCount(2);
    foreach ($readerNames as $name) {
        expect(route($name, ['id' => '00000000-0000-0000-0000-000000000000']))->toStartWith('http://workspace.example/beam/notifications/notifications/');
    }
});
