<?php

namespace Splicewire\Beam\Notifications\Support;

use RuntimeException;
use Splicewire\Beam\Notifications\Recipients\UnresolvableRecipientKind;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;

/**
 * Thrown when a record carries a `schema_ref` and the registry cannot answer it — beam-facade
 * ticket 62. Sibling to {@see UnresolvableRecipientKind}: the two members of this package's
 * "the operator must see this" family, on the same no-silent-drop reasoning.
 *
 * ## Why this is a defect and not a normal outcome
 * {@see SchemaTargetResolver::targetFor()} is a TOTAL port — an unknown stem returns `[]` — and for
 * most of its callers that is correct (an unknown intake slug is a 404, not an error). What makes
 * this case different is the RECORD: it carries a `schema_ref`, so it is registry-addressable by
 * construction, and ticket 47 made that the only tier it may answer from. A miss therefore means the
 * host mis-registered its artifact, forgot `config/data-schemas.php`, or stamped a stem nothing
 * matches — and before this class existed the whole evidence was an absence. The capture succeeded,
 * the request 201'd, and no mail was sent.
 *
 * ## Why throwing is safe here
 * Persist-then-notify: {@see \Splicewire\Beam\Notifications\Listeners\NotifyOnSubmission} wraps its
 * whole body and reports rather than rethrows, so this never turns a captured write into a 500.
 * That guarantee lives in the listener — the ONE boundary whose documented job it is — exactly as it
 * does for {@see UnresolvableRecipientKind}, which is thrown from a collaborator and swallowed
 * there. A future second caller of {@see RegistrySchemaResolver::resolve()} inherits the exception
 * and must decide for itself; had this been an inline `report()` returning `[]`, that caller would
 * have inherited ticket 62's own defect one layer up — unable to tell a miss from a record that
 * legitimately has no schema.
 *
 * ## What it reports, and why the resolver class is in the message
 * Ticket 53's rule: report the location the RESOLVED OBJECT is reading, never the config key that
 * was supposed to produce it. A host that misses most often has an empty registry or a different
 * {@see SchemaTargetResolver} bound than it thinks (ticket 48 found five of six hosts resolving the
 * `file` tier to a gitignored package default), and the ref alone cannot tell those apart.
 */
class UnresolvableSchemaRef extends RuntimeException
{
    /**
     * A record whose `schema_ref` stemmed cleanly and addressed nothing registered.
     *
     * @param  string  $ref  the `schema_ref` as stored on the record
     * @param  string  $stem  the record type it was reduced to — what the registry was actually asked
     * @param  string  $resolver  the concrete SchemaTargetResolver class the container answered with
     * @param  string|null  $id  the record's key, so the report names a row an operator can go read
     */
    public static function forStem(string $ref, string $stem, string $resolver, ?string $id = null): self
    {
        return new self(sprintf(
            'No registered schema for `%s` (stem `%s`), resolved through %s%s. The record carries a '.
            'schema_ref, so the registry is the only tier it may answer from (beam-facade 47) and no '.
            'x-beam-notify could be read. Register the artifact under that exact $id, or check which '.
            'SchemaTargetResolver and registry directory this host has bound.',
            $ref,
            $stem,
            $resolver,
            $id === null ? '' : sprintf(' (record %s)', $id),
        ));
    }

    /**
     * A record whose `schema_ref` could not be reduced to a stem at all — the same defect one step
     * earlier, and the reason this is not folded into {@see forStem()}: the repair is the stored
     * string, not the registry.
     */
    public static function forUnstemmableRef(string $ref, string $resolver, ?string $id = null): self
    {
        return new self(sprintf(
            'The schema_ref `%s` could not be reduced to a record type, so the registry (%s) was '.
            'never asked and no x-beam-notify could be read%s. A stored ref must be a bare stem or a '.
            'versioned $id.',
            $ref,
            $resolver,
            $id === null ? '' : sprintf(' (record %s)', $id),
        ));
    }
}
