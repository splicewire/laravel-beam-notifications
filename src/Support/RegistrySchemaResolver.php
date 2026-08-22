<?php

namespace Splicewire\Beam\Notifications\Support;

use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Submissions\RecordsSubmissions;

/**
 * Resolves the schema document carrying `x-beam-notify` for a persisted record, so
 * {@see \Splicewire\Beam\Notifications\Listeners\NotifyOnSubmission} can read the keyword at
 * fire-time. beam-notifications READS the keyword; it does not own schema resolution — beam-core's
 * {@see SchemaTargetResolver} port does, and this class is the RECORD-shaped adapter over that
 * TYPE-shaped port (`resolve(object $record)` vs `targetFor(string $recordType, ?int $version)`).
 *
 * There is deliberately no interface over this class (beam-facade ticket 40). It had one, justified
 * by "that's schema-forms' `SchemaRegistry`" — a package (`splicewire/laravel-beam-submissions`)
 * that no longer exists — and a census found zero rebinders anywhere in the estate. A host that
 * needs different policy binds a subclass against this class; the seam is the container binding,
 * not a one-implementation interface.
 *
 * Resolution order — the two tiers are ADDRESSED BY THE RECORD, not tried in sequence
 * (beam-facade ticket 47):
 *
 *  1. REGISTRY — for any record carrying a `schema_ref`. Its TYPE (via
 *     {@see PersistsBeamParticle::recordType()}) resolves through the bound
 *     {@see SchemaTargetResolver}. A record with a `schema_ref` is registry-addressable BY
 *     CONSTRUCTION, so the registry is the only correct answer for it — a miss does NOT fall through
 *     to a snapshot. It THROWS {@see UnresolvableSchemaRef} (ticket 62): a miss on an addressable
 *     record is a host misconfiguration, and returning `[]` for it made the only evidence an absence.
 *  2. SNAPSHOT — a schema document frozen onto the record itself (a `schema` attribute or
 *     `meta.schema`), consulted ONLY for a record carrying no `schema_ref` at all.
 *     {@see RecordsSubmissions} stamps this on every capture as write-time provenance.
 *
 * Returns `[]` only for a record with NOTHING to resolve — no `schema_ref` and no snapshot — and the
 * listener then does nothing. An addressable record that misses throws instead; see tier 1 above.
 *
 * ---
 *
 * WHY, because the obvious ordering is the wrong one (beam-facade tickets 40 and 47). The two tiers
 * embody contradictory rules: a record's PAYLOAD migrates forward through reconcile-on-read, while a
 * snapshot hands notify a schema frozen at write time. Under snapshot-first, a record reconciled
 * forward to v3 still read `x-beam-notify` from the frozen v2 — change the recipients in v3 and the
 * mail went to the old ones. Schema edits were effective for the payload and inert for notify.
 * Falling back to the snapshot ON A REGISTRY MISS would not have fixed it either: for an addressable
 * record the snapshot is ALWAYS the stale read, so the stale path had to become unreachable rather
 * than merely deprioritised.
 *
 * The precondition this waited on: the four hosts stamping snapshots (audiostud, fable, standwell,
 * stephenrushing) used to load their form schemas from `resources/schemas/forms/*.json` with no
 * registry artifacts, so inverting the tiers then would have taken every one of them dark —
 * silently, since a registry miss is `[]`, not an error. Ticket 48 dissolved that directory
 * estate-wide onto committed artifacts under absolute `$id`s, which is what made this edit safe.
 *
 * The reconciliation that makes tier 1 work, re-verified live at fable, standwell and
 * stephenrushing when the inversion landed: a stored `schema_ref` is a VERSIONED `$id` while
 * {@see SchemaTargetResolver::targetFor()} wants a STEM and appends the version itself. They agree
 * only because {@see PersistsBeamParticle::recordType()} strips the trailing integer — and because
 * `SchemaId` splits an absolute URI correctly despite the `https://` slashes.
 *
 * HISTORICAL ROWS are the one thing 48 could not fix and this rule changes their meaning. Pre-48
 * rows carry relative refs (`waitlist/1`, `interest/1`) that stem to themselves and are in no
 * registry, yet they DO carry a `schema_ref` — so their snapshot is now unreachable rather than a
 * fallback. Inert while notify fires on the persist event and not on read; the honest repair when
 * that changes is a `schema_ref` backfill to the absolute `$id`, never a softening of this rule.
 *
 * DELETE TIER 2 when no host writes `meta.schema` any more — a greppable condition, not an
 * intention. Ticket 41 converges the estate onto beam-core's generic intake door, after which every
 * record is registry-addressable and the snapshot answers for nobody.
 */
class RegistrySchemaResolver
{
    public function __construct(protected SchemaTargetResolver $targets) {}

    /**
     * The schema document for the given record, or an empty array when the record has nothing to
     * resolve from (no `schema_ref` and no snapshot).
     *
     * @param  object  $record  The beam particle (or host record) the submission references.
     * @return array<string, mixed>
     *
     * @throws UnresolvableSchemaRef when the record carries a `schema_ref` the registry cannot
     *                               answer — a host misconfiguration, not a normal outcome.
     */
    public function resolve(object $record): array
    {
        // 1. REGISTRY — the only tier an addressable record may answer from. A miss is final:
        //    falling back to the snapshot here would restore the stale read (see the class docblock).
        //    It is also a DEFECT rather than a normal outcome, so it throws (ticket 62) — the port
        //    below is total by contract and cannot know that; only this adapter holds both halves of
        //    the fact (the record carried a ref, AND the answer is now final).
        $ref = data_get($record, 'schema_ref');
        if (is_string($ref) && $ref !== '') {
            $type = method_exists($record, 'recordType')
                ? $record->recordType()
                : SchemaId::from($ref)->recordType();

            $id = data_get($record, 'id');
            $id = is_scalar($id) ? (string) $id : null;

            if (! is_string($type) || $type === '') {
                throw UnresolvableSchemaRef::forUnstemmableRef($ref, $this->targets::class, $id);
            }

            $target = $this->targets->targetFor($type);

            if ($target === []) {
                throw UnresolvableSchemaRef::forStem($ref, $type, $this->targets::class, $id);
            }

            return $target;
        }

        // 2. SNAPSHOT — write-time provenance, reachable only for a record with no `schema_ref`.
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
