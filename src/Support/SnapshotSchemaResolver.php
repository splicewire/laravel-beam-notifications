<?php

namespace Splicewire\Beam\Notifications\Support;

use Splicewire\Beam\Notifications\Contracts\SchemaResolver;

/**
 * The built-in snapshot-fallback schema resolver (spec §S). Reads a schema document the
 * record already carries, in priority order:
 *
 *  1. a `schema` attribute on the record (a stored snapshot), if it's an array;
 *  2. `meta.schema` (records that stash the snapshot under meta);
 *
 * and returns `[]` (do nothing) when neither is present. This keeps a standalone beam
 * working without schema-forms' `SchemaRegistry`; a host that wants canonical-by-`schema_ref`
 * resolution binds a registry-backed {@see SchemaResolver} instead.
 */
class SnapshotSchemaResolver implements SchemaResolver
{
    public function resolve(object $record): array
    {
        $snapshot = data_get($record, 'schema');

        if (is_array($snapshot)) {
            return $snapshot;
        }

        $metaSchema = data_get($record, 'meta.schema');

        if (is_array($metaSchema)) {
            return $metaSchema;
        }

        return [];
    }
}
