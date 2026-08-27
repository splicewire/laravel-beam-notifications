<?php

namespace Splicewire\Beam\Notifications\Contracts;

use Splicewire\Beam\Notifications\Recipients\Recipient;

/**
 * One registered KIND of `x-beam-notify` recipient selector — `to:`, `to_roles:`, `to_teams:`.
 *
 * ## Why this exists rather than a rebindable resolver (beam-facade 100, built by 159)
 *
 * The package used to declare ONE seam, {@see RecipientResolver}, and expect an accounts-aware
 * implementation to REBIND it wholesale in order to add two keys. That shape was the defect: the
 * would-be rebinder has to re-implement (or delegate to, and defensively strip keys from) the built-in
 * in order to add anything, so the seam's cost scales with the number of contributors and its only
 * non-fixture implementation in the estate's history was a test stub. It never got built.
 *
 * A kind contributes ONE key and knows nothing about the others. beam-notifications registers `to`;
 * beam-accounts appends `to_roles` / `to_teams` to the same config key; a host may append its own. The
 * registry is `beam.notifications.recipient_kinds`, so an unregistered kind is literally an absent
 * config entry — there is no `class_exists` probe in either direction, and nothing has to guess whether
 * a sibling package is installed.
 *
 * ## The grammar: `to` and `to_*` are selectors
 *
 * {@see \Splicewire\Beam\Notifications\Recipients\DefaultRecipientResolver} treats exactly the keys `to`
 * and `to_*` as recipient selectors, and every other key of the keyword (`channels`, `subject`,
 * `template`, `notification`) as message configuration. That is the same line 100 D4 drew for the
 * declared growth path: a future scope MODIFIER is spelled `in_team:`, `in_` and not `to_`, precisely
 * because a `to_*` key contributes recipients and a scope key contributes nobody.
 */
interface RecipientKind
{
    /**
     * Resolve this kind's declared selectors into concrete recipients.
     *
     * Returning an EMPTY list is a fault the caller turns into a throw
     * ({@see \Splicewire\Beam\Notifications\Recipients\NoRecipientsForKind}) — a schema that names a
     * role with no members has nobody to notify, and 100 D3 rules that a notification with nobody to
     * notify is a fault rather than a no-op. An implementation may throw something sharper itself when
     * it knows WHICH selector came up empty.
     *
     * @param  list<mixed>  $selectors  The keyword's value for this key, normalized to a list.
     * @param  array<string, mixed>  $context  The interpolation context ({payload, schema, submission}).
     * @return list<Recipient>
     */
    public function resolve(array $selectors, array $context): array;
}
