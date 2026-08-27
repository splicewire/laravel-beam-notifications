<?php

namespace Splicewire\Beam\Notifications\Recipients;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\RegistryArity;
use Splicewire\Beam\Notifications\Contracts\RecipientKind;

/**
 * The registry of `x-beam-notify` recipient KINDS — what replaced the rebindable resolver seam
 * (beam-facade 100, built by 159).
 *
 * Storage is `config('beam.notifications.recipient_kinds')`, a map of keyword key → class-string. This
 * package seeds `to`; `splicewire/laravel-beam-accounts` appends `to_roles` / `to_teams` from its own
 * provider; a host may append its own kind in its config file with no code here changing.
 *
 * ## The property that made this shape win
 *
 * An unregistered kind is an ABSENT CONFIG KEY. There is no `class_exists` probe, no `$app->bound()`
 * check, and no package interrogating whether a sibling is installed — the two options 100 weighed
 * (bind it in notifications, or bind it in accounts) both required exactly that, in one direction or
 * the other. A registry miss IS the "unresolvable kind" condition, so
 * {@see UnresolvableRecipientKind} stops being a hardcoded list of two keys this package has to keep
 * in step with a package it must not depend on.
 *
 * `ConfigRegistry` reads through to the repository on every read rather than snapshotting at
 * construction, which is load-bearing here: beam-accounts registers in `packageBooted()`, after this
 * package's own provider has already run.
 *
 * ## `PickOne`, and entries are class-strings
 *
 * One key resolves to one kind. The entries stay class-strings resolved through the container by
 * {@see DefaultRecipientResolver} — the config array is the storage, so the registry hands back what
 * the host wrote there rather than quietly making it, and a kind may declare constructor dependencies
 * (the accounts kinds inject {@see \Splicewire\Beam\Notifications\Contracts\AccountsDirectory}).
 */
#[IsRegistry(
    root: 'beam.notifications.recipient_kinds',
    of: 'x-beam-notify recipient selector kinds — the `to` / `to_*` keys and the class that resolves each',
    arity: RegistryArity::PickOne,
    entryType: 'class-string<'.RecipientKind::class.'>',
    onDuplicate: OnDuplicate::Supersede,
    optionality: Optionality::Optional,
    note: 'Keys are the keyword key verbatim (`to`, `to_roles`, `to_teams`). A registry MISS is the '
        .'unresolvable-kind condition, which is why no participant needs a class_exists guard.',
)]
class RecipientKindRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'beam.notifications.recipient_kinds';
    }

    /**
     * Every registered keyword key, as a host spelled it — for an error message that can name what IS
     * available rather than only what is not.
     *
     * @return list<string>
     */
    public function declaredKeys(): array
    {
        return $this->store()->relativeKeys();
    }
}
