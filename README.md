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

## The three recipient keys

| key | resolves to | channels |
|---|---|---|
| `to:` | literal address(es) or a payload-ref (`{{ payload.email }}`) → on-demand mail | **mail-only** (no persistent Notifiable) |
| `to_roles:` | role name(s) → member **models** | full channels **incl. the `database` inbox** |
| `to_teams:` | team ref → team-member **models** | full channels **incl. the `database` inbox** |

The channel constraint is structural: the in-app inbox (`database` channel) requires a persistent
`Notifiable`, so it is only reachable through `to_roles`/`to_teams`; `to:` is mail-only by
construction.

`to:` works standalone. `to_roles`/`to_teams` resolve through
`splicewire/laravel-beam-accounts` (a **soft** dependency) — without it, using them is a clear
hard error (never a silent no-recipient send).

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

## Seams you can rebind

| binding | default | rebind to |
|---|---|---|
| `RecipientResolver` | address-only `to:` | beam-accounts' accounts-aware resolver (`to_roles`/`to_teams`) |
| `SchemaResolver` | record-carried snapshot (`schema` / `meta.schema`) | a `schema_ref`-canonical registry resolver |

## Config

`config/beam-notifications.php` — `listen` (master trigger switch) and `default_channels`.
