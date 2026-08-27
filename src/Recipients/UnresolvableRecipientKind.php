<?php

namespace Splicewire\Beam\Notifications\Recipients;

use RuntimeException;
use Splicewire\Beam\Notifications\Contracts\RecipientKind;

/**
 * Thrown when `x-beam-notify` declares a recipient selector no {@see RecipientKind} is registered for —
 * i.e. a MISS on {@see RecipientKindRegistry}.
 *
 * This used to be a hardcoded guard: `DefaultRecipientResolver` carried the literal list
 * `['to_roles', 'to_teams']` and threw on either, because those were the two keys the spec named and
 * nothing could resolve. That made the condition "a key beam-notifications has heard of and cannot
 * handle" — so a host's own selector was silently ignored rather than reported, and the two names had
 * to be kept in step with a package this one must not depend on.
 *
 * It is now the general condition: a key that is a selector by grammar (`to` / `to_*`) and has no entry
 * in the registry. Installing `splicewire/laravel-beam-accounts` registers `to_roles` / `to_teams` and
 * the miss stops happening, which is the same remedy the old message named — reached by a config key
 * being present rather than by a binding being replaced.
 *
 * Still a HARD error rather than a silent drop: a beam that asks to notify somebody it cannot identify
 * has a misconfiguration the operator must see, not a notification quietly sent nowhere.
 */
class UnresolvableRecipientKind extends RuntimeException
{
    /**
     * @param  list<string>  $registered  Every kind that IS registered, so the message can name the
     *                                    difference between "not installed" and "misspelled".
     */
    public static function forKey(string $key, array $registered = []): self
    {
        return new self(sprintf(
            'The `%s` recipient key of x-beam-notify has no registered recipient kind. Registered: %s. '
                .'Install splicewire/laravel-beam-accounts for `to_roles` / `to_teams`, register your own '
                .'kind under `beam.notifications.recipient_kinds.%s`, or remove `%s` from the keyword.',
            $key,
            $registered === [] ? '(none)' : implode(', ', $registered),
            $key,
            $key,
        ));
    }
}
