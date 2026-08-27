<?php

namespace Splicewire\Beam\Notifications\Recipients;

use Illuminate\Contracts\Container\Container;
use Splicewire\Beam\Notifications\Contracts\RecipientKind;

/**
 * Resolves every recipient selector of `x-beam-notify` by DISPATCHING to the kind registered for it
 * (beam-facade 100, built by 159). It resolves nobody itself.
 *
 * ## What this class used to be, and why the shape changed
 *
 * It used to be "the built-in, accounts-free resolver": it handled `to:` inline, carried a hardcoded
 * guard throwing on the literal keys `to_roles` / `to_teams`, and expected
 * `splicewire/laravel-beam-accounts` to REBIND the whole `RecipientResolver` contract in order to add
 * those two. Measured 2026-08-24 and again on 2026-08-26: that rebinding did not exist anywhere in the
 * family, in either package, at any host — and the suite was green because a test fixture inside this
 * package stood in for the class under test. Half the keyword threw at every host in the estate while
 * two docblocks said it was one composer require away.
 *
 * The seam was the reason. To add one key a rebinder had to supply, or defensively delegate to and
 * strip keys from, the built-in — so the cost of contributing scaled with the number of contributors
 * and the first contributor was never written. Kinds compose instead: each owns exactly one key,
 * beam-accounts appends two to {@see RecipientKindRegistry}, and neither package probes whether the
 * other is installed.
 *
 * ## The grammar
 *
 * A key is a recipient selector iff it is `to` or begins with `to_`. Everything else in the keyword
 * (`channels`, `subject`, `template`, `notification`) is message configuration and is not this class's
 * business. A selector whose value is empty declares nobody and is skipped — a keyword with no
 * selectors at all resolves to no recipients and the dispatcher simply does not send, which is
 * unchanged.
 *
 * ## The two terminals (100 D3)
 *
 * A selector with no registered kind throws {@see UnresolvableRecipientKind} — a wiring fault. A
 * selector whose registered kind resolves to nobody throws {@see NoRecipientsForKind} — a content
 * fault. The old single terminal could not tell those apart, and the old `to:` path had a third,
 * invisible one: it dropped an empty address and returned successfully.
 *
 * All three surface as a reported-and-swallowed error at {@see \Splicewire\Beam\Notifications\Listeners\NotifyOnSubmission}'s
 * one boundary rather than as a 500, because the record is already durable by the time this runs.
 *
 * ## Not an interface any more
 *
 * The `Contracts\RecipientResolver` port is gone with the rebinding it existed for. This follows the
 * precedent this package already set at beam-facade 40 for `RegistrySchemaResolver`: an interface whose
 * only implementations were the default and a fixture, that no host ever rebound, is a seam nobody
 * asked for. A host that needs different dispatch policy binds a subclass against this class; a host
 * that needs a different SELECTOR — the actual extension point — registers a kind.
 */
class DefaultRecipientResolver
{
    public function __construct(
        protected RecipientKindRegistry $kinds,
        protected Container $container,
    ) {}

    /**
     * @param  array<string, mixed>  $notify  The parsed `x-beam-notify` keyword.
     * @param  array<string, mixed>  $context  The interpolation context ({payload, schema, submission}).
     * @return list<Recipient>
     */
    public function resolve(array $notify, array $context): array
    {
        $recipients = [];

        foreach ($notify as $key => $value) {
            if (! is_string($key) || ! $this->isSelector($key)) {
                continue;
            }

            $selectors = array_values(array_filter(
                is_array($value) ? $value : [$value],
                fn (mixed $selector): bool => $selector !== null && $selector !== '',
            ));

            if ($selectors === []) {
                continue;
            }

            $resolved = $this->kind($key)->resolve($selectors, $context);

            if ($resolved === []) {
                throw NoRecipientsForKind::forKey($key, $selectors);
            }

            $recipients = [...$recipients, ...$resolved];
        }

        return $recipients;
    }

    /**
     * `to` and `to_*`. See the class docblock — `in_*` is reserved for the declared scope-modifier
     * growth path precisely so that this predicate stays "does it contribute recipients".
     */
    protected function isSelector(string $key): bool
    {
        return $key === 'to' || str_starts_with($key, 'to_');
    }

    /**
     * The registered kind for a selector key, made through the container so a kind may declare
     * constructor dependencies — which is how the accounts kinds reach
     * {@see \Splicewire\Beam\Notifications\Contracts\AccountsDirectory}.
     */
    protected function kind(string $key): RecipientKind
    {
        $registered = $this->kinds->tryResolve($key);

        if (! is_string($registered) || $registered === '') {
            throw UnresolvableRecipientKind::forKey($key, $this->kinds->declaredKeys());
        }

        return $this->container->make($registered);
    }
}
