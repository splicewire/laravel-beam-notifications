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

it('registers the default root beside the legacy mount, inside the host boundary', function () {
    Route::prefix('host')->group(fn () => Resources::register());
    Route::domain('workspace.example')->middleware('auth')->group(fn () => NotificationsRoutes::mount());

    $current = app(Router::class)->getRoutes()->match(Request::create('http://workspace.example/beam/notifications/notification-statuses'));

    expect(notificationsRouteTable('beam/notifications/'))->toBe(notificationsRouteTable('host/'))
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

    expect(notificationsRouteTable('custom/notify/'))->toBe(notificationsRouteTable('host/'))
        ->and(notificationsRouteTable('beam/notifications/'))->toBeEmpty();
});
