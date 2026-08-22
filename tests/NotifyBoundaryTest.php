<?php

use Illuminate\Support\Facades\Exceptions;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Notifications\Support\UnresolvableSchemaRef;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;

/**
 * The listener boundary, asserted as behaviour (beam-facade ticket 62). `NotifyOnSubmission` is the
 * ONE place the persist-then-notify guarantee lives: everything its collaborators throw is reported
 * and swallowed, so a misconfigured notify can never turn a captured write into a 500.
 *
 * This is the load-bearing half of ticket 62's gate. Making the schema miss THROW was the right
 * encoding — a miss on an addressable record is a defect, not an outcome — but its entire risk is a
 * lost capture, and before this file nothing asserted the swallow at all. Note what the try used to
 * cover: `dispatch()` alone, with `resolve()` called above it, so this test would have gone red on
 * the very first throw.
 */
function fireUnregisteredRef(): void
{
    app()->instance(SchemaTargetResolver::class, new class implements SchemaTargetResolver
    {
        public function targetFor(string $recordType, ?int $version = null): array
        {
            return [];
        }
    });

    $record = new BeamParticle([
        'schema_ref' => 'https://schemas.example.test/intake/waitlist/1',
        'payload' => ['email' => 'ada@example.test'],
    ]);

    event(new BeamParticlePersisted($record, ['email' => 'ada@example.test'], 'intake/waitlist'));
}

it('does not let an unresolvable schema_ref escape the listener onto the write path', function () {
    Exceptions::fake();

    // The event is fired from the write chain's terminal EmitStage, synchronously — anything that
    // escapes here surfaces as a 500 on a request whose record has already saved.
    fireUnregisteredRef();
})->throwsNoExceptions();

it('reports the unresolvable schema_ref rather than dropping it silently', function () {
    Exceptions::fake();

    fireUnregisteredRef();

    // The whole point of ticket 62: before this, the capture succeeded, the request 201'd, no mail
    // was sent, and the only evidence was an absence.
    Exceptions::assertReported(UnresolvableSchemaRef::class);
});

it('reports nothing for a capture whose schema simply carries no x-beam-notify', function () {
    Exceptions::fake();

    fireRecordPersisted(null);

    // A resolvable schema with no notify keyword is the ordinary case and must stay quiet — the
    // signal is worthless if it fires for every write that was never meant to notify.
    Exceptions::assertNothingReported();
});
