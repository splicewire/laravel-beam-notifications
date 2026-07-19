<?php

namespace Splicewire\Beam\Notifications\Recipients;

use RuntimeException;
use Splicewire\Beam\Notifications\Contracts\RecipientResolver;

/**
 * Thrown when a schema declares `to_roles` / `to_teams` but no accounts-aware
 * {@see RecipientResolver} is bound (i.e.
 * `splicewire/laravel-beam-accounts` is not installed, or hasn't rebound the resolver).
 *
 * This is a deliberate HARD dev error rather than a silent no-recipient drop (no silent
 * drop doctrine): a beam that asks to notify a role/team but cannot resolve members has a
 * misconfiguration the operator must see, not a notification quietly sent nowhere.
 */
class UnresolvableRecipientKind extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf(
            'The `%s` recipient key requires an accounts-aware RecipientResolver. Install '.
            'splicewire/laravel-beam-accounts (it rebinds the resolver), or remove `%s` from '.
            'the x-beam-notify keyword. The built-in resolver handles only address-only `to:`.',
            $key,
            $key,
        ));
    }
}
