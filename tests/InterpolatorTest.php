<?php

use Schemastud\Beam\Notifications\Support\Interpolator;

it('substitutes a {{ path }} token with a dot-path value', function () {
    $out = Interpolator::render('Hello {{ payload.name }}', [
        'payload' => ['name' => 'Ada'],
    ]);

    expect($out)->toBe('Hello Ada');
});

it('renders unknown paths as empty and never throws', function () {
    $out = Interpolator::render('x={{ payload.missing }}=y', ['payload' => []]);

    expect($out)->toBe('x==y');
});

it('does NOT execute a Blade directive embedded in the payload (inert text)', function () {
    // The payload is untrusted; a Blade directive stored in it must render as-is, not run.
    $malicious = '@php echo 1+1; @endphp {{ 7 }}';

    $out = Interpolator::render('body: {{ payload.note }}', [
        'payload' => ['note' => $malicious],
    ]);

    // The payload value comes through verbatim — no `2`, no evaluated `{{ 7 }}` (single pass,
    // resolved values are NOT re-interpolated), no PHP execution.
    expect($out)->toBe('body: @php echo 1+1; @endphp {{ 7 }}');
});

it('does not re-interpolate a value that itself looks like a token (no pivot)', function () {
    $out = Interpolator::render('{{ payload.a }}', [
        'payload' => ['a' => '{{ payload.secret }}', 'secret' => 'LEAK'],
    ]);

    expect($out)->toBe('{{ payload.secret }}')
        ->not->toContain('LEAK');
});

it('HTML-escapes substituted values when escaping is requested (mail bodies)', function () {
    $out = Interpolator::render('{{ payload.x }}', [
        'payload' => ['x' => '<script>alert(1)</script>'],
    ], escape: true);

    expect($out)->toBe('&lt;script&gt;alert(1)&lt;/script&gt;');
});
