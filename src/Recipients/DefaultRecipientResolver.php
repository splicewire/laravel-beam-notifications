<?php

namespace Splicewire\Beam\Notifications\Recipients;

use Splicewire\Beam\Notifications\Contracts\RecipientResolver;
use Splicewire\Beam\Notifications\Support\Interpolator;

/**
 * The built-in, accounts-free resolver (spec §2). Handles ONLY the address-only `to:` key:
 *
 *  - literal addresses (`ops@site.test`)
 *  - payload-ref addresses (`{{ payload.email }}`) — interpolated against the send context
 *
 * Each becomes an on-demand mail {@see Recipient::address()} (mail-only by construction —
 * no persistent Notifiable, so the `database` inbox is unreachable here).
 *
 * `to_roles:` / `to_teams:` are NOT handled: this resolver throws
 * {@see UnresolvableRecipientKind} rather than silently dropping them.
 *
 * ## ⚠️ NO ACCOUNTS-AWARE RESOLVER EXISTS — measured 2026-08-24, re-measured 2026-08-26
 *
 * This docblock used to say an accounts-aware resolver was "bound by splicewire/laravel-beam-accounts,
 * the soft dep", present tense. It is not. `splicewire/laravel-beam-accounts` has zero matches for
 * `RecipientResolver`, `to_roles` or `to_teams`, and the only implementation of the contract anywhere
 * in the family besides this class is `tests/Fixtures/StubAccountsRecipientResolver.php` — a fixture
 * inside this package's own suite. So `to_roles:` / `to_teams:` throws at EVERY host in the family,
 * beam-accounts installed or not, and the suite is green because a fixture stands in for the class
 * under test (beam-facade tickets 100 and 79).
 *
 * The seam is real and the throw is right; what is missing is any implementation. WHICH package should
 * own one is an open layering call — beam-notifications declares the seam and owns the keyword, which
 * argues for an optional binding here, against the assumption above that it belongs in beam-accounts —
 * and it is beam-facade ticket 100. Do not read this comment as a plan; read it as the reason
 * `x-beam-notify: to_roles:` cannot be authored today.
 */
class DefaultRecipientResolver implements RecipientResolver
{
    public function resolve(array $notify, array $context): array
    {
        foreach (['to_roles', 'to_teams'] as $accountsKey) {
            if (! empty($notify[$accountsKey])) {
                throw UnresolvableRecipientKind::forKey($accountsKey);
            }
        }

        $recipients = [];

        foreach ((array) ($notify['to'] ?? []) as $target) {
            if (! is_string($target)) {
                continue;
            }

            $address = str_contains($target, '{{')
                ? Interpolator::render($target, $context)
                : $target;

            $address = trim($address);

            if ($address !== '') {
                $recipients[] = Recipient::address($address);
            }
        }

        return $recipients;
    }
}
