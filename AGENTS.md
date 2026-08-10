> You are in **splicewire/laravel-beam-notifications** — the notify capability of the schemastud beam family.

A Laravel package that owns the `x-beam-notify` keyword and the submission → notify wiring:
when a `BeamSubmission` is created, it resolves recipients (`to` / `to_roles` / `to_teams`) and
sends a notification, synthesizing one generic `BeamNotification` from the keyword. It composes
`rushing/laravel-notification-status` for delivery tracking and `splicewire/laravel-beam` for the
submission pipeline rather than reimplementing either.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
