<?php

namespace Splicewire\Beam\Notifications\Contracts;

use Splicewire\Beam\Notifications\Support\SnapshotSchemaResolver;

/**
 * Resolves the schema document (carrying `x-beam-notify`) for a submission at fire-time
 * (spec §S). beam-notifications READS the keyword; it does NOT own schema resolution —
 * that's schema-forms' `SchemaRegistry`. This contract is the seam:
 *
 *  - the built-in {@see SnapshotSchemaResolver} reads a
 *    schema snapshot the record carries (its `schema` attribute or `meta.schema`) — enough for
 *    a standalone beam and the snapshot-fallback case.
 *  - a host that resolves canonical schemas by `schema_ref` (public forms via schema-forms'
 *    file-based registry) binds a registry-backed resolver over this contract.
 *
 * @template TRecord of object
 */
interface SchemaResolver
{
    /**
     * Return the schema document for the given record, or an empty array when none is
     * resolvable (the listener then does nothing — no `x-beam-notify`, no send).
     *
     * @param  object  $record  The SchemaRecord (or host record) the submission references.
     * @return array<string, mixed>
     */
    public function resolve(object $record): array;
}
