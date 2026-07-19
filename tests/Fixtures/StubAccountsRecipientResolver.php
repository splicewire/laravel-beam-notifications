<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Splicewire\Beam\Notifications\Contracts\RecipientResolver;
use Splicewire\Beam\Notifications\Recipients\DefaultRecipientResolver;
use Splicewire\Beam\Notifications\Recipients\Recipient;

/**
 * A stand-in for the accounts-aware resolver that splicewire/laravel-beam-accounts binds when
 * installed (§2). It delegates `to:` to the built-in default and additionally turns `to_roles`
 * / `to_teams` into persistent member models — proving the soft-dep integration point without
 * pulling the whole accounts package into this suite.
 */
class StubAccountsRecipientResolver implements RecipientResolver
{
    public function __construct(protected DefaultRecipientResolver $default = new DefaultRecipientResolver) {}

    public function resolve(array $notify, array $context): array
    {
        // `to:` is address-only; reuse the built-in (but strip roles/teams first so the
        // built-in's hard-error guard doesn't fire on keys WE handle).
        $addressOnly = $notify;
        unset($addressOnly['to_roles'], $addressOnly['to_teams']);

        $recipients = $this->default->resolve($addressOnly, $context);

        foreach (['to_roles', 'to_teams'] as $key) {
            foreach ((array) ($notify[$key] ?? []) as $name) {
                foreach (TeamMemberUser::query()->where('role', $name)->orWhere('team', $name)->get() as $member) {
                    $recipients[] = Recipient::notifiable($member);
                }
            }
        }

        return $recipients;
    }
}
