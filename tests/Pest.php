<?php

use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Notifications\Tests\TestCase;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;

uses(TestCase::class)->in('.');

/**
 * Bind a one-stem {@see SchemaTargetResolver} so `targetFor('contact')` answers with `$schema` and
 * every other stem misses (`[]`, the port's total no-target case). Stands in for a host's registered
 * artifact without touching the filesystem registry, whose `register()` is write-once and would make
 * a second run of the suite non-idempotent.
 *
 * @param  array<string, mixed>  $schema
 */
function registerSchemaTarget(string $stem, array $schema): void
{
    app()->instance(SchemaTargetResolver::class, new class($stem, $schema) implements SchemaTargetResolver
    {
        /** @param array<string, mixed> $schema */
        public function __construct(protected string $stem, protected array $schema) {}

        public function targetFor(string $recordType, ?int $version = null): array
        {
            return $recordType === $this->stem ? $this->schema : [];
        }
    });
}

/**
 * Build a BeamParticle in the shape every host in the estate writes post-ticket-48 — a `schema_ref`
 * addressing a REGISTERED schema — then emit the ONE beam write-pipeline signal,
 * {@see BeamParticlePersisted}, for it (ticket 05 replaces the retired BeamSubmission::created trigger).
 *
 * The `x-beam-notify` keyword is read off the registry (tier 1), not off a snapshot: ticket 47 made
 * the snapshot unreachable for any record carrying a `schema_ref`. A `meta.schema` snapshot is
 * stamped alongside anyway, because {@see \Splicewire\Beam\Submissions\RecordsSubmissions} still
 * writes one as write-time provenance — so these fixtures also hold the tier order honest: were it
 * ever to invert back, they would resolve the snapshot and nothing would go red.
 *
 * The record need not be saved: the listener reads the payload straight off the event.
 *
 * @param  array<string, mixed>|null  $notify  The x-beam-notify keyword body (null = none).
 * @param  array<string, mixed>  $payload
 */
function fireRecordPersisted(?array $notify, array $payload = []): BeamParticle
{
    $schema = ['title' => 'Contact', 'type' => 'object'];

    if ($notify !== null) {
        $schema['x-beam-notify'] = $notify;
    }

    registerSchemaTarget('contact', $schema);

    $record = new BeamParticle([
        'schema_ref' => 'contact/1',
        'payload' => $payload,
        'meta' => [
            'schema' => $schema,
            'intake' => ['submitted_at' => '2026-07-29T00:00:00+00:00', 'source' => 'web', 'channel' => 'public-intake'],
        ],
    ]);

    event(new BeamParticlePersisted($record, $payload, 'contact'));

    return $record;
}
