<?php

namespace Splicewire\Beam\Notifications\Recipients;

use RuntimeException;

/**
 * Thrown when a recipient kind IS registered, resolves its selectors, and comes back with nobody.
 *
 * The second of the two terminals beam-facade 100 D3 rules — and it exists because the single terminal
 * that preceded it could not tell these apart:
 *
 *  - the kind is unregistered → {@see UnresolvableRecipientKind}, a wiring fault;
 *  - the kind is registered and the selection is empty → this, a content fault.
 *
 * The owner's ruling: **a notification with nobody to notify is a fault**, not a no-op. `to_roles:
 * ['admin']` in a database with no admin is a schema whose intent did not happen, and the alternative —
 * resolving to nobody and returning quietly — is the failure mode this estate keeps paying for, an
 * instrument that reports success by not running.
 *
 * ## This extends to `to:`, which is a behaviour change to a shipped path
 *
 * `DefaultRecipientResolver` used to drop an address that rendered empty (`$address !== ''`), so a
 * mistyped `{{ payload.emial }}` mailed nobody in silence — `Interpolator` renders an unknown path
 * empty and never throws, so the two halves composed into a silent no-send. That drop is now this
 * throw. Measured 2026-08-27 across the estate before landing it: nine live schemas declare `to:`, and
 * the only interpolated one (`schemastud`'s feedback intake, `{{ payload.email }}`) interpolates a
 * REQUIRED property — so no host's current traffic reaches this. It is a real behaviour change all the
 * same, and it is in the changelog.
 */
class NoRecipientsForKind extends RuntimeException
{
    /**
     * @param  list<mixed>  $selectors  What the keyword declared, so the message names the miss.
     */
    public static function forKey(string $key, array $selectors, ?string $because = null): self
    {
        return new self(sprintf(
            'The `%s` recipient key of x-beam-notify declared %s and resolved to nobody%s. A '
                .'notification with no recipient is a fault, not a silent no-op — fix the selector, or '
                .'remove `%s` from the keyword.',
            $key,
            $selectors === [] ? 'nothing' : '`'.implode('`, `', array_map('strval', $selectors)).'`',
            $because !== null ? ' ('.$because.')' : '',
            $key,
        ));
    }
}
