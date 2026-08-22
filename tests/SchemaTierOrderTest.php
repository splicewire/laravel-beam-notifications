<?php

use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Notifications\Support\RegistrySchemaResolver;
use Splicewire\Beam\Notifications\Support\UnresolvableSchemaRef;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;

/**
 * Ticket 47's rule, asserted as behaviour rather than as an ordering: the tiers are ADDRESSED BY THE
 * RECORD. A `schema_ref` means registry-and-only-registry; its absence is the only thing that makes a
 * frozen snapshot reachable. The stale-read defect 40 diagnosed comes back the moment a miss falls
 * through, so the miss case below is the load-bearing one.
 */
function resolveSchemaFor(BeamParticle $record): array
{
    return app(RegistrySchemaResolver::class)->resolve($record);
}

it('reads the registry, not the snapshot, for a record carrying a schema_ref', function () {
    registerSchemaTarget('contact', ['title' => 'Contact v3', 'x-beam-notify' => ['to' => ['current@site.test']]]);

    $resolved = resolveSchemaFor(new BeamParticle([
        'schema_ref' => 'contact/1',
        'meta' => ['schema' => ['title' => 'Contact v1', 'x-beam-notify' => ['to' => ['stale@site.test']]]],
    ]));

    expect($resolved['title'])->toBe('Contact v3')
        ->and($resolved['x-beam-notify']['to'])->toBe(['current@site.test']);
});

it('throws rather than falling through to the snapshot when the registry misses an addressable record', function () {
    registerSchemaTarget('something-else', ['title' => 'Not this one']);

    // The fixture carries a stale `meta.schema` ON PURPOSE: a fall-through would resolve it and
    // return the v1 recipients, which is the stale read ticket 40 diagnosed and 47 made unreachable.
    // Throwing asserts that at least as strongly as the `[]` this used to expect — and, per ticket
    // 62, asserts the other half too: the miss is a host misconfiguration, so the only evidence is
    // no longer an absence.
    expect(fn () => resolveSchemaFor(new BeamParticle([
        'schema_ref' => 'contact/1',
        'meta' => ['schema' => ['title' => 'Contact v1', 'x-beam-notify' => ['to' => ['stale@site.test']]]],
    ])))->toThrow(UnresolvableSchemaRef::class);
});

it('names the stem and the bound resolver in the miss, not the config key that was meant to produce them', function () {
    registerSchemaTarget('something-else', ['title' => 'Not this one']);

    // Ticket 53's rule, carried onto the runtime signal: a host that misses usually has an empty
    // registry or a different resolver bound than it believes, and the ref alone cannot tell those
    // apart.
    expect(fn () => resolveSchemaFor(new BeamParticle(['schema_ref' => 'contact/1'])))
        ->toThrow(UnresolvableSchemaRef::class, 'stem `contact`');
});

it('strips the version off a stored schema_ref before addressing the registry', function () {
    registerSchemaTarget('https://schemas.example.test/intake/waitlist', ['title' => 'Waitlist']);

    $resolved = resolveSchemaFor(new BeamParticle([
        'schema_ref' => 'https://schemas.example.test/intake/waitlist/2',
    ]));

    expect($resolved['title'])->toBe('Waitlist');
});

it('reads the snapshot for a record carrying no schema_ref at all', function () {
    registerSchemaTarget('contact', ['title' => 'Registered']);

    // `meta.schema` is the particle's snapshot — a bare `schema` attribute is not fillable on
    // BeamParticle, so that half of the tier only ever answers for a host's own record shape.
    expect(resolveSchemaFor(new BeamParticle([
        'meta' => ['schema' => ['title' => 'From meta.schema']],
    ]))['title'])->toBe('From meta.schema');

    $hostRecord = new class
    {
        public array $schema = ['title' => 'From the schema attribute'];
    };

    expect(app(RegistrySchemaResolver::class)->resolve($hostRecord)['title'])
        ->toBe('From the schema attribute');
});

it('resolves nothing for a record with neither a schema_ref nor a snapshot', function () {
    expect(resolveSchemaFor(new BeamParticle(['payload' => ['name' => 'Ada']])))->toBe([]);
});

it('does NOT treat a record with no schema_ref as a miss', function () {
    registerSchemaTarget('something-else', ['title' => 'Not this one']);

    // Ticket 62's boundary, as a gate rather than a comment: the trigger is strictly
    // ref-present-and-unanswerable. A record with no ref has nothing to be wrong about — it is
    // tier 2's legitimate case today, and it stays `[]` — so a snapshot read must never raise. This
    // goes red the day someone widens the throw over the tier-2 population ahead of ticket 41.
    expect(resolveSchemaFor(new BeamParticle(['meta' => ['schema' => ['title' => 'Frozen']]])))
        ->toBe(['title' => 'Frozen']);

    expect(resolveSchemaFor(new BeamParticle(['payload' => ['name' => 'Ada']])))->toBe([]);
});

it('addresses the registry off a schema_ref even when the record cannot stem it itself', function () {
    app()->instance(SchemaTargetResolver::class, new class implements SchemaTargetResolver
    {
        public function targetFor(string $recordType, ?int $version = null): array
        {
            return ['stem' => $recordType];
        }
    });

    // A host record with a schema_ref but no recordType() — the resolver derives the stem itself
    // rather than dropping to the snapshot, which is the hole a method_exists check alone leaves.
    $record = new class
    {
        public string $schema_ref = 'legacy/waitlist/4';

        public array $meta = ['schema' => ['title' => 'Frozen']];
    };

    expect(app(RegistrySchemaResolver::class)->resolve($record))->toBe(['stem' => 'legacy/waitlist']);
});
