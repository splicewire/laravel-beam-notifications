<?php

namespace Splicewire\Beam\Notifications\Support;

use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
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
 * Resolution order, in two tiers:
 *
 *  1. SNAPSHOT — a schema document frozen onto the record itself (a `schema` attribute or
 *     `meta.schema`). {@see RecordsSubmissions} stamps this on every capture, so it is the only
 *     tier that answers for a host whose form schemas live at a file path and were never
 *     registered as versioned artifacts.
 *  2. REGISTRY — beam's canonical schema registry, resolving the record's TYPE (via
 *     {@see PersistsBeamParticle::recordType()}) through the bound {@see SchemaTargetResolver}.
 *     This is what makes public-intake notifications work: a particle written through the generic
 *     door carries a `schema_ref`, not a full snapshot.
 *
 * Returns `[]` when nothing resolves — the listener then does nothing (no `x-beam-notify`, no send).
 *
 * ---
 *
 * KNOWN DEFECT, and the tier order is the whole of it (beam-facade ticket 40). The two tiers
 * embody contradictory rules: a record's PAYLOAD migrates forward through reconcile-on-read, while
 * tier 1 hands notify a schema frozen at write time. So on a record reconciled forward to v3,
 * `x-beam-notify` is still read from the frozen v2 — and if v3 changed the recipients, the mail
 * goes to the old ones. Schema edits are effective for the payload and inert for notify.
 *
 * The repair is to ask the registry FIRST and consult a snapshot only for a record that carries no
 * `schema_ref` at all (a record with one is registry-addressable by construction, so its snapshot
 * is always the stale read). That is NOT applied here — but the prerequisite that blocked it is now
 * CLOSED. The four hosts stamping snapshots (audiostud, fable, standwell, stephenrushing) used to
 * load their form schemas from `resources/schemas/forms/*.json` with no registry artifacts, so
 * inverting the tiers alone would have taken every one of them dark — silently, since a registry
 * miss is `[]`, not an error. Beam-facade ticket 48 dissolved that directory estate-wide: all four
 * now register their schema as a committed artifact under an absolute `$id`, and each was verified
 * to resolve through tier 2 live. **Ticket 47 is therefore the tier-order edit in {@see resolve()}
 * and nothing else.**
 *
 * One thing 47 should re-run rather than trust, because it fails silently if it is ever untrue: a
 * stored `schema_ref` is a VERSIONED `$id` while {@see SchemaTargetResolver::targetFor()} wants a
 * STEM and appends the version itself. They reconcile only because
 * {@see PersistsBeamParticle::recordType()} strips the trailing integer — and because `SchemaId`
 * splits an absolute URI correctly despite the `https://` slashes.
 *
 * DELETE TIER 1 when no host writes `meta.schema` any more — a greppable condition, not an
 * intention. Ticket 41 converges the estate onto beam-core's generic intake door, after which every
 * record is registry-addressable and tier 1 answers for nobody.
 */
class RegistrySchemaResolver
{
    public function __construct(protected SchemaTargetResolver $targets) {}

    /**
     * The schema document for the given record, or an empty array when none is resolvable.
     *
     * @param  object  $record  The beam particle (or host record) the submission references.
     * @return array<string, mixed>
     */
    public function resolve(object $record): array
    {
        // 1. A snapshot carried on the record. See the tier-order defect above (ticket 47).
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
