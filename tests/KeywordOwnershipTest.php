<?php

use Splicewire\Beam\Notifications\Keywords;

it('owns x-beam-notify under the x-beam family prefix', function () {
    expect(Keywords::Notify)->toBe('x-beam-notify')
        ->and(Keywords::Prefix)->toBe('x-beam')
        ->and(Keywords::owned())->toContain('x-beam-notify')
        ->and(str_starts_with(Keywords::Notify, Keywords::Prefix.'-'))->toBeTrue();
});

it('no longer names the retired x-swf-notify keyword', function () {
    // §K: the keyword MOVED (rename + generalization); the old name is gone from this owner.
    expect(Keywords::owned())->not->toContain('x-swf-notify');
});

it('every owned keyword carries the declared family prefix', function () {
    foreach (Keywords::owned() as $keyword) {
        expect(str_starts_with($keyword, Keywords::Prefix))->toBeTrue();
    }
});
