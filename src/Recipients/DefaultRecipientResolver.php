<?php

namespace Schemastud\Beam\Notifications\Recipients;

use Schemastud\Beam\Notifications\Contracts\RecipientResolver;
use Schemastud\Beam\Notifications\Support\Interpolator;

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
 * {@see UnresolvableRecipientKind} rather than silently dropping them. An accounts-aware
 * resolver (bound by schemastud/laravel-beam-accounts, the soft dep) replaces this binding
 * to add role/team member resolution.
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
