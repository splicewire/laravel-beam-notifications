<?php

namespace Splicewire\Beam\Notifications;

/**
 * The JSON-Schema extension keywords THIS package owns.
 *
 * Ownership doctrine (the JSON-LD `@context` model): the base leaf owns the small
 * cross-engine set (`@id`, `x-dereference`); every other package owns and guards its
 * OWN keywords locally. There is no central keyword list to curate — a keyword is
 * legitimate because some package declares it here, and drift is caught by each package
 * asserting what it reads/emits stays within `base ∪ own` (see KeywordOwnershipTest).
 *
 * `x-beam-notify` is the RENAME + generalization of schema-forms' retired `x-swf-notify`
 * (spec §K): the notify verb is now a `beam` CAPABILITY (peer of beam-accounts /
 * beam-commerce), not schema-forms' private outbox machinery — so the keyword moves under
 * the family `x-beam` prefix and this package owns it. schema-forms owns it no longer.
 */
class Keywords
{
    /**
     * The declared family prefix. `x-beam-*` keywords belong to the schemastud beam
     * capability family; this package registers the prefix and owns `x-beam-notify`.
     */
    public const Prefix = 'x-beam';

    public const Notify = 'x-beam-notify';

    /**
     * Every `x-` keyword this package owns / reads.
     *
     * @return list<string>
     */
    public static function owned(): array
    {
        return [self::Notify];
    }
}
