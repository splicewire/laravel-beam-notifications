<?php

namespace Splicewire\Beam\Notifications\Listeners;

use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Notifications\Keywords;
use Splicewire\Beam\Notifications\Support\NotificationDispatcher;
use Splicewire\Beam\Notifications\Support\RegistrySchemaResolver;

/**
 * The persisted-record -> notify wiring (beam-write-pipeline ticket 05). Bound to the ONE
 * {@see BeamParticlePersisted} event every beam write path emits (ADR-0150) — no longer to the retired
 * `BeamSubmission::created` corpse. So a public-intake submission, a Frame edit, an adopted CRUD write,
 * or the generation populator can ALL drive a notification through this single listener.
 *
 * On the event: resolve the record's schema (§S), read `x-beam-notify`; if absent, do nothing; if
 * present, build the {payload, schema, submission} context and hand to the {@see NotificationDispatcher}
 * (recipients §2, generic-or-override §1). "submission" context is now the record's intake provenance
 * (`meta.intake`, written by the public door) — the same template-safe view, sourced from the record.
 *
 * The record is already durable when this runs (the pipeline emits AFTER the save), so a
 * failing/misconfigured notifier must never turn a captured write into a 500 — it is reported and
 * swallowed. That is the request-side half of persist-then-notify, and it is why the try wraps the
 * WHOLE body rather than the dispatch alone (beam-facade ticket 62). It used to cover only
 * `dispatch()`, which left the guarantee true of the two lines someone thought of: this listener is
 * registered with a plain synchronous `Event::listen`, fired from the write chain's terminal
 * `EmitStage`, so anything thrown above the try propagated back through `ParticleWriter` to the
 * controller — a 500 on a request whose record had already saved.
 *
 * A genuine misconfiguration the operator must see still surfaces, because swallowed here means
 * `report()`ed, not discarded. Two throw from collaborators and are caught at this ONE boundary —
 * `UnresolvableRecipientKind` (roles/teams with no accounts resolver) and `UnresolvableSchemaRef`
 * (a `schema_ref` the registry cannot answer). A boot-time guard elsewhere is the louder signal.
 */
class NotifyOnSubmission
{
    public function __construct(
        protected RegistrySchemaResolver $schemaResolver,
        protected NotificationDispatcher $dispatcher,
    ) {}

    public function handle(BeamParticlePersisted $event): void
    {
        if (! config('beam.notifications.listen', true)) {
            return;
        }

        try {
            $record = $event->record;

            $schema = $this->schemaResolver->resolve($record);

            $notify = $schema[Keywords::Notify] ?? null;

            if (! is_array($notify) || $notify === []) {
                return;
            }

            $context = [
                'payload' => $event->payload,
                'schema' => $schema,
                'submission' => $this->submissionContext($record),
            ];

            $this->dispatcher->dispatch(
                $notify,
                $context,
                (array) config('beam.notifications.default_channels', ['mail']),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * A flat, template-safe view of the record's intake provenance (written under `meta.intake` by the
     * public door) for the interpolation context — the successor to the retired submission model's fields.
     *
     * @return array<string, mixed>
     */
    protected function submissionContext(object $record): array
    {
        $intake = (array) data_get($record, 'meta.intake', []);

        return [
            'id' => data_get($record, 'id'),
            'submitted_at' => (string) ($intake['submitted_at'] ?? ''),
            'source' => $intake['source'] ?? null,
            'channel' => $intake['channel'] ?? null,
        ];
    }
}
