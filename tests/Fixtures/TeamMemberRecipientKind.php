<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Splicewire\Beam\Notifications\Contracts\RecipientKind;
use Splicewire\Beam\Notifications\Recipients\Recipient;

/**
 * A HOST-registered recipient kind, standing in for nobody.
 *
 * It replaces `StubAccountsRecipientResolver`, which was a stand-in for beam-accounts' resolver — the
 * defect beam-facade 79/100 named, a fixture standing in for the class under test, which is why this
 * suite was green for two years while `to_roles:` threw at every host in the estate.
 *
 * This one is not a stand-in: registering a kind under a `to_*` key is a first-class thing a host may
 * do, and the class under test here is the dispatcher and its registry, not the kind. The real
 * `to_roles` / `to_teams` kinds live in `splicewire/laravel-beam-accounts` and are tested there,
 * against real memberships.
 */
class TeamMemberRecipientKind implements RecipientKind
{
    public function resolve(array $selectors, array $context): array
    {
        $recipients = [];

        foreach ($selectors as $name) {
            foreach (TeamMemberUser::query()->where('role', $name)->orWhere('team', $name)->get() as $member) {
                $recipients[] = Recipient::notifiable($member);
            }
        }

        return $recipients;
    }
}
