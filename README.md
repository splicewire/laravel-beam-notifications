# Laravel Beam Notifications

**"A beam can notify."** The notify capability of the schemastud `beam` family — a peer of
`splicewire/laravel-beam-accounts` and `-beam-commerce`. It owns the `x-beam-notify` keyword and
the submission → notify wiring, and is deliberately thin: delivery tracking, retry/replay, the
in-app inbox, and the central relay all live in packages it composes, never re-implements.

## What it does

A form schema declares an `x-beam-notify` keyword; when a `BeamSubmission` is created, the
package resolves recipients and sends a notification — with **no PHP** for the common case.

```yaml
x-beam-notify:
  # recipients — declare any combination
  to:        ["ops@site.test", "{{ payload.email }}"]   # on-demand, mail-only
  to_roles:  [admin]                                     # role members (models), full channels
  to_teams:  ["{{ payload.team_id }}"]                   # team members (models), full channels

  # channels — name strings; via() intersects them with the app's registered drivers
  channels:  [mail, database, central]

  # message — the generic renderer (logic-less {{ path }} interpolation, NOT Blade)
  subject:   "New {{ schema.title }} submission"
  template:  "{{ payload.name }} wrote: {{ payload.message }}"

  # ...OR a host-class override — when present, subject/template are ignored
  notification: App\Notifications\ContactReceived
```

## Recipient kinds

A key spelled `to` or `to_*` is a **recipient selector**. Each one is resolved by the *kind*
registered for it in `config('beam.notifications.recipient_kinds')` — a map of keyword key →
class-string. Everything else in the keyword (`channels`, `subject`, `template`, `notification`) is
message configuration.

| key | registered by | resolves to | channels |
|---|---|---|---|
| `to:` | this package | literal address(es) or a payload-ref (`{{ payload.email }}`) → on-demand mail | **mail-only** (no persistent Notifiable) |
| `to_roles:` | `splicewire/laravel-beam-accounts` | membership role name(s) → member **models** | full channels **incl. the `database` inbox** |
| `to_teams:` | `splicewire/laravel-beam-accounts` | team slug(s) → team-member **models** | full channels **incl. the `database` inbox** |

The channel constraint is structural: the in-app inbox (`database` channel) requires a persistent
`Notifiable`, so it is only reachable through `to_roles`/`to_teams`; `to:` is mail-only by
construction.

`to:` works standalone. `to_roles`/`to_teams` arrive with `splicewire/laravel-beam-accounts` (a
**soft** dependency), which appends them to the same config key and binds the `AccountsDirectory`
port they resolve through. Without that package they are simply unregistered — and an unregistered
selector is a hard error, never a silent no-recipient send.

**A host can add its own kind.** Implement `Contracts\RecipientKind`, register it under
`beam.notifications.recipient_kinds.to_whatever`, and `to_whatever:` is authorable in a schema. No
package involved has to probe whether another is installed: an unregistered kind is an absent
config entry.

### Two terminals, and no third silent one

- A selector with **no registered kind** throws `UnresolvableRecipientKind` — a wiring fault.
- A selector whose kind resolves to **nobody** throws `NoRecipientsForKind` — a content fault. A
  notification with no recipient is a fault, not a no-op.

⚠️ **Behaviour change:** that second rule now covers `to:` as well. An address that interpolates to
nothing (`{{ payload.emial }}` — unknown paths render empty and never throw) used to be dropped, so
a typo mailed nobody and reported nothing. It throws now. See `CHANGELOG.md`.

## The `central` channel (relay) is NOT here

`central` is only a channel-**name** string a schema may list. This package ships no relay code.
A host that wants central delivery registers a custom channel in its own provider:

```php
Notification::extend('central', fn ($app) => new CentralRelayChannel(/* ... */));
```

On a headless beam that never loads that provider, `central` is unregistered, and the generic
notification's `via()` intersection drops it — the relay simply doesn't happen, no crash. In
Splicewire the `central` channel lives in `splicewire/laravel-satellite-*`.

## Delivery tracking — automatic, zero wiring

`rushing/laravel-notification-status` (a **soft** dependency) records every notification's
`pending`/`sent`/`failed`/`given_up` lifecycle by subscribing to Laravel's native notification
events. Because this package sends real Laravel notifications, status is recorded automatically —
there is no tracking code here to couple to.

## Trigger

Submission-only: `BeamSubmission::created`. Generalizing notify to the generation or manual-edit
populators is a future seam, not wired here.

## Templates are logic-less

`subject`/`template` use a `{{ dot.path }}` substitution over a fixed `{payload, schema,
submission}` context — **not Blade**, no code evaluation. The submission payload is untrusted
input, so a Blade directive or `{{ ... }}` stored in it is inert text. Unknown paths render empty.
A host that wants real templating uses the `notification:` override and owns its own view.

## Seams

The extension point is the **recipient-kind registry** above — you contribute a key, you do not
replace a mechanism. There is no rebindable `RecipientResolver` port; it was removed by beam-facade
100 after two years in which the accounts-aware rebinding it existed for was never written in either
package, and half the keyword threw at every host while two docblocks said it was one composer
require away. `DefaultRecipientResolver` is now a concrete dispatcher over registered kinds; a host
that needs different dispatch *policy* binds a subclass against the class.

Schema resolution is deliberately **not** a seam here. `RegistrySchemaResolver` is a concrete class
the listener depends on directly: it reads a snapshot frozen on the record (`schema` /
`meta.schema`), then falls back to beam-core's `SchemaTargetResolver` port by the record's
`schema_ref`. It carried an interface until beam-facade ticket 40, justified by a package that no
longer exists and never rebound by any host. A host needing different policy binds a subclass
against the class.

## Config

`config/beam/notifications.php`, read as `config('beam.notifications.*')` — `listen` (master
trigger switch), `default_channels`, `recipient_kinds` (above) and `resources` (the delivery-ledger
particle surface).
