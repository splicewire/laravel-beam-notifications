<?php

namespace Splicewire\Beam\Notifications\Tests\Fixtures;

use Splicewire\Beam\Notifications\Contracts\RecipientKind;
use Splicewire\Beam\Notifications\Recipients\Recipient;

/**
 * A host-registered recipient kind that resolves a selector to TENANT-SIDE REPLICAS, the shape
 * beam-accounts' `to_roles` / `to_teams` kinds return under a tenant guard: `Recipient::notifiable($replica)`,
 * where `$replica` is a {@see TenantReplicaUser} that is NOT itself notifiable and whose notification rows
 * belong to the central person behind it (attention-surfaces decision 02, ruling 3).
 *
 * It mirrors {@see TeamMemberRecipientKind}, but over the replica table — so a kind reaching
 * {@see \Splicewire\Beam\Notifications\Support\NotificationDispatcher::send()} with a non-notifiable
 * replica is exercised the way AS11's third face reaches it. The dispatcher must map each such recipient
 * through {@see \Splicewire\Beam\Notifications\Contracts\NotifiableForActor} before `Notification::send`.
 */
class ReplicaMemberRecipientKind implements RecipientKind
{
    public function resolve(array $selectors, array $context): array
    {
        $recipients = [];

        foreach ($selectors as $name) {
            foreach (TenantReplicaUser::query()->where('role', $name)->get() as $replica) {
                $recipients[] = Recipient::notifiable($replica);
            }
        }

        return $recipients;
    }
}
