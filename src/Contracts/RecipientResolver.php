<?php

namespace Schemastud\Beam\Notifications\Contracts;

use Schemastud\Beam\Notifications\Recipients\DefaultRecipientResolver;
use Schemastud\Beam\Notifications\Recipients\Recipient;

/**
 * Resolves the three typed recipient keys of `x-beam-notify` into concrete
 * {@see Recipient}s (spec §2). The seam is what keeps a headless address-only notify beam
 * free of `beam-accounts`:
 *
 *  - beam-notifications ships the built-in
 *    {@see DefaultRecipientResolver}, which handles
 *    `to:` (literal + payload-ref addresses) and NOTHING else — `to_roles`/`to_teams` throw
 *    a clear error there (no silent no-recipient send).
 *  - `schemastud/laravel-beam-accounts` (a SOFT dep) rebinds this contract to an
 *    accounts-aware resolver that additionally resolves `to_roles` -> role-member models and
 *    `to_teams` -> team-member models.
 *
 * So `to:` always works standalone; `to_roles`/`to_teams` pull accounts.
 */
interface RecipientResolver
{
    /**
     * Resolve every declared recipient key into a flat list of recipients.
     *
     * @param  array<string, mixed>  $notify  The parsed `x-beam-notify` keyword.
     * @param  array<string, mixed>  $context  The interpolation context ({payload, schema, submission}).
     * @return list<Recipient>
     */
    public function resolve(array $notify, array $context): array;
}
