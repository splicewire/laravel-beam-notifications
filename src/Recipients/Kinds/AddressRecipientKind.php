<?php

namespace Splicewire\Beam\Notifications\Recipients\Kinds;

use Splicewire\Beam\Notifications\Contracts\RecipientKind;
use Splicewire\Beam\Notifications\Recipients\NoRecipientsForKind;
use Splicewire\Beam\Notifications\Recipients\Recipient;
use Splicewire\Beam\Notifications\Support\Interpolator;

/**
 * The `to:` kind — the one this package registers, and the whole of what a headless, accounts-free beam
 * can address (spec §2).
 *
 * Each selector is a literal address (`ops@site.test`) or a payload-ref (`{{ payload.email }}`)
 * interpolated against the send context, and becomes an on-demand mail {@see Recipient::address()} —
 * mail-only by construction, since there is no persistent Notifiable, so the `database` inbox is
 * unreachable from this kind. That constraint is structural, not policy: only the model shape can carry
 * `database`, which is what `to_roles:` / `to_teams:` are for.
 *
 * ## An address that renders empty now THROWS
 *
 * This class used to be the body of `DefaultRecipientResolver`, and it silently skipped an address that
 * came back empty. Composed with `Interpolator` — whose context is fixed at
 * `['payload','schema','submission']` and which renders an unknown path empty and never throws — that
 * meant a mistyped `{{ payload.emial }}` mailed nobody, reported nothing, and returned successfully.
 * beam-facade 100 D3 rules that condition a fault; see {@see NoRecipientsForKind} for the measurement
 * taken before landing it.
 */
class AddressRecipientKind implements RecipientKind
{
    public function resolve(array $selectors, array $context): array
    {
        $recipients = [];

        foreach ($selectors as $target) {
            if (! is_string($target)) {
                continue;
            }

            $address = trim(str_contains($target, '{{')
                ? Interpolator::render($target, $context)
                : $target);

            if ($address === '') {
                throw NoRecipientsForKind::forKey(
                    'to',
                    [$target],
                    'it rendered empty — an unknown interpolation path renders empty and never throws, '
                        .'so check the spelling of the payload field',
                );
            }

            $recipients[] = Recipient::address($address);
        }

        return $recipients;
    }
}
