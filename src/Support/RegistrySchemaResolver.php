<?php

namespace Splicewire\Beam\Notifications\Support;

use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Notifications\Contracts\SchemaResolver;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;

/**
 * The default schema resolver after the write-pipeline rewire (beam-write-pipeline ticket 05). Given a
 * persisted record, it finds the schema document that carries `x-beam-notify`, in priority order:
 *
 *  1. a snapshot the record already carries — a `schema` attribute or `meta.schema` (the old
 *     {@see SnapshotSchemaResolver} behaviour, kept so a host that embeds the snapshot still works);
 *  2. beam's canonical schema registry — resolve the record's TYPE (via the record's `recordType()`)
 *     through the bound {@see SchemaTargetResolver}. ({@see PersistsBeamParticle::recordType()})
 *
 * The registry tier is what makes public-intake notifications work: a beam particle written through
 * the public door carries its `schema_ref` (not a full schema snapshot), so the keyword is read from the
 * registered target schema, not from the record. Returns `[]` when nothing resolves — the listener then
 * does nothing (no `x-beam-notify`, no send).
 */
class RegistrySchemaResolver implements SchemaResolver
{
    public function __construct(protected SchemaTargetResolver $targets) {}

    public function resolve(object $record): array
    {
        // 1. A snapshot carried on the record.
        $snapshot = data_get($record, 'schema');
        if (is_array($snapshot)) {
            return $snapshot;
        }

        $metaSchema = data_get($record, 'meta.schema');
        if (is_array($metaSchema)) {
            return $metaSchema;
        }

        // 2. Beam's canonical registry, by the record's type.
        $type = method_exists($record, 'recordType') ? $record->recordType() : null;
        if (is_string($type) && $type !== '') {
            return $this->targets->targetFor($type);
        }

        return [];
    }
}
