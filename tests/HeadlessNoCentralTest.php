<?php

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;
use Schemastud\Beam\Notifications\Notifications\BeamNotification;

it('ships NO central-channel implementation in the package (headless beam carries no relay)', function () {
    // §3: beam-notifications knows `central` only as a channel-NAME string. It ships no
    // CentralRelayChannel, no transport, no relay config. Prove the source tree contains no
    // such class and never calls Notification::extend('central', ...).
    $srcFiles = collect(
        (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src')))
    )->filter(fn ($f) => $f->isFile() && $f->getExtension() === 'php');

    expect($srcFiles)->not->toBeEmpty();

    foreach ($srcFiles as $file) {
        // Strip comments before scanning: a docblock may legitimately NAME the `central`
        // pattern to explain the boundary; what must not exist is executable relay code.
        $tokens = token_get_all(file_get_contents($file->getPathname()));
        $code = '';
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        expect($code)
            ->not->toContain("extend('central'")
            ->not->toContain('extend("central"')
            ->not->toContain('CentralRelayChannel');
    }

    // And no class named for a central relay channel is autoloadable from the package.
    expect(class_exists('Schemastud\\Beam\\Notifications\\Channels\\CentralRelayChannel'))->toBeFalse();
});

it('drops an unregistered `central` channel in via() without crashing (graceful degrade)', function () {
    // A platform-authored schema lists [mail, database, central]; on a headless beam `central`
    // is never registered, so via() intersects it away — the relay simply does not happen.
    $notification = new BeamNotification(
        channels: ['mail', 'database', 'central'],
        subject: 'S',
        template: 'B',
        context: [],
    );

    $via = $notification->via(new class
    {
        use Notifiable;
    });

    expect($via)->toContain('mail')
        ->toContain('database')
        ->not->toContain('central');
});

it('keeps a `central` channel when a host DOES register it (via() intersection lets it through)', function () {
    // The satellite provider registers it: Notification::extend('central', ...). Simulate that
    // registration here to prove via() is a pure registered-driver intersection, not a hardcoded
    // allowlist — the ONLY thing that makes `central` real is the host binding it.
    Notification::extend('central', function ($app) {
        // Reuse the database channel machinery as an inert stand-in transport for the test.
        return $app->make(DatabaseChannel::class);
    });

    $notification = new BeamNotification(
        channels: ['mail', 'central'],
        subject: 'S',
        template: 'B',
        context: [],
    );

    $via = $notification->via(new class
    {
        use Notifiable;
    });

    expect($via)->toContain('mail')->toContain('central');
});
