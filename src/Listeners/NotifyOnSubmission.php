<?php

namespace Splicewire\Beam\Notifications\Listeners;

use Splicewire\Beam\Notifications\Contracts\SchemaResolver;
use Splicewire\Beam\Notifications\Keywords;
use Splicewire\Beam\Notifications\Support\NotificationDispatcher;

/**
 * The submission -> notify wiring (spec §T). Bound to `BeamSubmission::created` (the
 * submission populator, the ONLY trigger — generalizing to the generation / manual-edit
 * populators is an explicit out-of-scope seam pointer, not built here).
 *
 * On the event: resolve the referenced record's schema (§S), read `x-beam-notify`; if
 * absent, do nothing; if present, build the {payload, schema, submission} context and hand
 * to the {@see NotificationDispatcher} (recipients §2, generic-or-override §1).
 *
 * The submission is already durable when this runs (created fires post-insert), so a
 * failing/misconfigured notifier must never turn a captured submission into a 500 — it is
 * reported and swallowed. That is the request-side half of persist-then-notify. A genuine
 * misconfiguration the operator must see (roles/teams with no accounts resolver) still
 * surfaces: UnresolvableRecipientKind is reported here, and a boot-time guard elsewhere is
 * the louder signal.
 */
class NotifyOnSubmission
{
    public function __construct(
        protected SchemaResolver $schemaResolver,
        protected NotificationDispatcher $dispatcher,
    ) {}

    /**
     * @param  object  $submission  The BeamSubmission that was just created.
     */
    public function handle(object $submission): void
    {
        if (! config('beam-notifications.listen', true)) {
            return;
        }

        $record = $this->record($submission);

        if ($record === null) {
            return;
        }

        $schema = $this->schemaResolver->resolve($record);

        $notify = $schema[Keywords::Notify] ?? null;

        if (! is_array($notify) || $notify === []) {
            return;
        }

        $context = [
            'payload' => (array) data_get($record, 'payload', []),
            'schema' => $schema,
            'submission' => $this->submissionContext($submission),
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
     * The record the submission references. Uses the `record` relation when present
     * (BeamSubmission::record()), falling back to a directly attached record.
     */
    protected function record(object $submission): ?object
    {
        if (method_exists($submission, 'record')) {
            $record = $submission->record ?? $submission->record()->getResults();

            if (is_object($record)) {
                return $record;
            }
        }

        $direct = data_get($submission, 'record');

        return is_object($direct) ? $direct : null;
    }

    /**
     * A flat, template-safe view of the submission for the interpolation context.
     *
     * @return array<string, mixed>
     */
    protected function submissionContext(object $submission): array
    {
        return [
            'id' => data_get($submission, 'id'),
            'submitted_at' => (string) data_get($submission, 'submitted_at'),
            'source' => data_get($submission, 'source'),
            'channel' => data_get($submission, 'channel'),
        ];
    }
}
