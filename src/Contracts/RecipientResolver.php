<?php

namespace Splicewire\Beam\Notifications\Contracts;

use Splicewire\Beam\Notifications\Recipients\DefaultRecipientResolver;
use Splicewire\Beam\Notifications\Recipients\Recipient;

/**
 * Resolves the three typed recipient keys of `x-beam-notify` into concrete
 * {@see Recipient}s (spec §2). The seam is what keeps a headless address-only notify beam
 * free of `beam-accounts`:
 *
 *  - beam-notifications ships the built-in
 *    {@see DefaultRecipientResolver}, which handles
 *    `to:` (literal + payload-ref addresses) and NOTHING else — `to_roles`/`to_teams` throw
 *    a clear error there (no silent no-recipient send).
 *  - an accounts-aware resolver rebinds this contract to additionally resolve
 *    `to_roles` -> role-member models and `to_teams` -> team-member models.
 *
 * So `to:` always works standalone; `to_roles`/`to_teams` need an accounts-aware binding.
 *
 * ## ⚠️ THAT BINDING DOES NOT EXIST ANYWHERE IN THE FAMILY (measured 2026-08-24 / 2026-08-26)
 *
 * This docblock previously named `splicewire/laravel-beam-accounts` as the soft dep that supplies it,
 * in the present tense. That package contains no implementation of this contract and no reference to
 * `to_roles`/`to_teams` at all. The only non-default implementation in the estate is this package's
 * own test fixture. Consequently `to_roles:` / `to_teams:` throws at every host, and the keyword is
 * half-unresolvable rather than one composer require away.
 *
 * Which package should own the real one is beam-facade ticket 100 and is deliberately NOT settled
 * here — this seam declares the keyword, which is an argument for an optional binding shipped beside
 * it; putting it in beam-accounts is the other. The claim is removed rather than restated because a
 * source-text check believes whatever a file says about itself (beam-facade ticket 77).
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
