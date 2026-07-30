<?php

use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Models\SchemaRecord;
use Splicewire\Beam\Notifications\Tests\TestCase;

uses(TestCase::class)->in('.');

/**
 * Build a SchemaRecord carrying an `x-beam-notify` schema snapshot (under meta.schema, which the default
 * RegistrySchemaResolver reads first) plus a payload, then emit the ONE beam write-pipeline signal —
 * {@see BeamParticlePersisted} — for it (ticket 05 replaces the retired BeamSubmission::created trigger).
 * The record need not be saved: the listener reads the snapshot + payload straight off the event.
 *
 * @param  array<string, mixed>|null  $notify  The x-beam-notify keyword body (null = none).
 * @param  array<string, mixed>  $payload
 */
function fireRecordPersisted(?array $notify, array $payload = []): SchemaRecord
{
    $schema = ['title' => 'Contact', 'type' => 'object'];

    if ($notify !== null) {
        $schema['x-beam-notify'] = $notify;
    }

    $record = new SchemaRecord([
        'schema_ref' => 'contact',
        'payload' => $payload,
        'meta' => [
            'schema' => $schema,
            'intake' => ['submitted_at' => '2026-07-29T00:00:00+00:00', 'source' => 'web', 'channel' => 'public-intake'],
        ],
    ]);

    event(new BeamParticlePersisted($record, $payload, 'contact'));

    return $record;
}
