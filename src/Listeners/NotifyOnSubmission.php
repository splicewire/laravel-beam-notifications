<?php

namespace Splicewire\Beam\Notifications\Listeners;

use Splicewire\Beam\Events\SchemaRecordPersisted;
use Splicewire\Beam\Notifications\Contracts\SchemaResolver;
use Splicewire\Beam\Notifications\Keywords;
use Splicewire\Beam\Notifications\Support\NotificationDispatcher;

/**
 * The persisted-record -> notify wiring (beam-write-pipeline ticket 05). Bound to the ONE
 * {@see SchemaRecordPersisted} event every beam write path emits (ADR-0150) — no longer to the retired
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
 * swallowed. That is the request-side half of persist-then-notify. A genuine misconfiguration the
 * operator must see (roles/teams with no accounts resolver) still surfaces: UnresolvableRecipientKind is
 * reported here, and a boot-time guard elsewhere is the louder signal.
 */
class NotifyOnSubmission
{
    public function __construct(
        protected SchemaResolver $schemaResolver,
        protected NotificationDispatcher $dispatcher,
    ) {}

    public function handle(SchemaRecordPersisted $event): void
    {
        if (! config('beam-notifications.listen', true)) {
            return;
        }

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

        try {
            $this->dispatcher->dispatch(
                $notify,
                $context,
                (array) config('beam-notifications.default_channels', ['mail']),
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
