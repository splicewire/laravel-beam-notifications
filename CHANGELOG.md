# Changelog — splicewire/laravel-beam-notifications

## Unreleased

### ⚠️ Behaviour change — a `to:` address that resolves to nothing now throws

`DefaultRecipientResolver` used to drop an address that came back empty (`$address !== ''`).
Composed with `Interpolator` — whose context is fixed at `{payload, schema, submission}` and which
renders an unknown path **empty and never throws** — that meant a mistyped `{{ payload.emial }}`
mailed nobody, reported nothing, and returned successfully. It now throws `NoRecipientsForKind`,
which the notify listener reports at its one boundary rather than turning into a 500.

**This affects every host with a `to:` key.** Measured across the estate on 2026-08-27 before
landing it: nine live schemas declare `to:`, eight of them a literal address, and the only
interpolated one (`schemastud`'s feedback intake, `{{ payload.email }}`) interpolates a **required**
property — so no host's current traffic reaches the new throw. A schema that interpolates an
**optional** field into `to:` will now fail loudly where it previously mailed nobody quietly; give
it a literal fallback or drop the selector.

Same ruling for the other selectors: a registered kind that resolves to nobody (`to_roles: [admin]`
with no admin) throws rather than sending nothing. beam-facade 100 D3.

### The rebindable `RecipientResolver` port is removed; recipient KINDS are the extension point

`Contracts\RecipientResolver` is deleted. Recipient selectors are now resolved through
`config('beam.notifications.recipient_kinds')` — a popcorn `ConfigRegistry` of keyword key →
`Contracts\RecipientKind` class-string. This package registers `to`;
`splicewire/laravel-beam-accounts` appends `to_roles` / `to_teams`; a host may append its own.

`UnresolvableRecipientKind` is now literally a registry miss rather than a hardcoded guard on the
two key names — so an unregistered host selector is reported instead of silently ignored, and no
package probes whether another is installed.

Nothing in the estate implemented the removed port (measured 2026-08-24 and again 2026-08-26: zero
implementations outside this package, whose only non-default one was a test fixture), so the removal
breaks no known consumer. A host that had rebound it implements a kind instead.

### New: `Contracts\AccountsDirectory`

The port the accounts-shaped kinds resolve through — `membersOfRole()` / `membersOfTeam()`, in
notifiable terms only. Implemented and bound by `splicewire/laravel-beam-accounts`. No `User`,
`Role` or `Team` vocabulary crosses into this package.

### New dependency: `rushing/laravel-popcorn`

For the registry kernel. Previously present transitively via `splicewire/laravel-beam`; now
declared, because this package uses it directly.
