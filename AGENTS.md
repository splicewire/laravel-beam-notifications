> You are in **splicewire/laravel-beam-notifications** — the notify capability of the schemastud beam family.

A Laravel package that owns the `x-beam-notify` keyword and the submission → notify wiring:
when a `BeamSubmission` is created, it resolves recipients (`to` / `to_roles` / `to_teams`) and
sends a notification, synthesizing one generic `BeamNotification` from the keyword. It composes
`rushing/laravel-notification-status` for delivery tracking and `splicewire/laravel-beam` for the
submission pipeline rather than reimplementing either.

## The producer rule: Review or the bell, never both

Decision 02 (attention-surfaces; the flagship's ADR-0225, "attention is three surfaces behind one bell"). For a package
author:

> If something waits on a person's answer, attach `workflow.await` and it will appear in Review; if you are only telling
> them, send one `database` notification with a `kind` and a message reference; never both.

- **The test is whether something resumes.** If a unit of work is suspended until this person answers, it is a decision
  and belongs in Review. When information later needs a decision, the producer starts the workflow that awaits it; the
  earlier notification stays as history.
- **What a `database` notification stores.** A producer-owned `kind` (an open string), and the sentence as a message
  reference under the `messageReference` data key, in `Splicewire\Beam\Data\MessageReferenceData::toProperties()`'s
  shape. The `notifications` resource renders that reference in each reader's language (stored reference, else a
  registered `notification` identity presenter, else the stored text unchanged). New product notifications store a kind
  and a reference and no English sentence. A producer with legacy fields already in storage (commerce's auto-reload
  notices) keeps them byte-identical and appends the reference; nothing is back-filled.
- **A link** goes under `action_url` and is shown as stored.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
