<?php

use Splicewire\Beam\Models\BeamSubmission;
use Splicewire\Beam\Models\SchemaRecord;
use Splicewire\Beam\Notifications\Tests\TestCase;

uses(TestCase::class)->in('.');

/**
 * Create a SchemaRecord carrying an `x-beam-notify` schema snapshot (under meta.schema, which
 * the built-in SnapshotSchemaResolver reads) plus a payload, then fire a BeamSubmission for it.
 * Returns the created submission (the `created` event has already fired synchronously).
 *
 * @param  array<string, mixed>|null  $notify  The x-beam-notify keyword body (null = none).
 * @param  array<string, mixed>  $payload
 */
function fireSubmission(?array $notify, array $payload = [], array $submissionAttrs = []): BeamSubmission
{
    $schema = ['title' => 'Contact', 'type' => 'object'];

    if ($notify !== null) {
        $schema['x-beam-notify'] = $notify;
    }

    $record = SchemaRecord::create([
        'schema_ref' => 'contact',
        'payload' => $payload,
        'meta' => ['schema' => $schema],
    ]);

    return BeamSubmission::create(array_merge([
        'schema_record_id' => $record->getKey(),
        'submitted_at' => now(),
        'source' => 'web',
        'channel' => 'form',
    ], $submissionAttrs));
}
