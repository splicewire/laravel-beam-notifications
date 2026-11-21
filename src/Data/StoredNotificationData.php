<?php

namespace Splicewire\Beam\Notifications\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Rushing\DataFilters\Attributes\Sortable;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\Frame\Attributes\Column;
use Schemastud\Frame\Attributes\NotInList;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Authorization\AuthenticatedReadPolicy;
use Splicewire\Beam\Localization\Presentation\MessagePresenters;
use Splicewire\Beam\Notifications\Particle\Backing\OwnStoredNotificationsBacking;
use Splicewire\Beam\Notifications\Summary\StoredNotificationSummaryProvider;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The `notifications` resource: a reader's own stored notifications, newest first, and nobody else's
 * (attention-surfaces 09; decision 02, rulings 2-5 and 12). Minted with `splicewire:beam:make:particle-resource
 * --read-only --summary` and moved into this package, which owns the table (ticket 08).
 *
 * WHOSE ROWS: the backing, {@see OwnStoredNotificationsBacking}, a `ResourceBacking` class-string resolved per request.
 * It reads the notifiable that ticket 08's port resolves for the signed-in actor, through that notifiable's own
 * relation, on the notifiable's connection. A tenant request's `TenantUser` is therefore read as the central person
 * behind it, never from the bare model on the ambient tenant connection (P5). An actor the port cannot place reads
 * nothing. The summary counts through the same backing, so the list and the count cannot disagree about whose rows
 * they are.
 *
 * WHAT A ROW SAYS: {@see self::project()} applies localization 04's reader rule through beam's generic reader, for
 * the current reader's locale. A stored message reference renders; else a registered `notification` identity presenter
 * renders from the row's identity (commerce's auto-reload kinds); else the stored text is shown unchanged (`message`,
 * or an `x-beam-notify` row's authored `subject`, or its `body` when the subject is empty). The wire carries rendered
 * text, never a key. Nothing is back-filled.
 *
 * THE PRODUCER CONTRACT for new product prose (decision 02, scope 6; the package's AGENTS.md, "The producer rule"):
 * store a `kind` and the sentence as a message reference under the `data` key **`messageReference`**, in
 * {@see \Splicewire\Beam\Data\MessageReferenceData::toProperties()}'s shape, and no English sentence. A producer whose
 * legacy fields are already in storage (commerce's auto-reload notices) keeps them byte-identical and appends the
 * reference; nothing is back-filled. A malformed or unknown-version reference falls back to the row's stored text when
 * it has one, never to the key.
 *
 * `kind` is a producer-owned open string (decision 02), not a closed vocabulary. `link` is the stored `action_url`,
 * or an existing commerce row's `cta_url`, unchanged: the list neither rewrites nor hides it, and a link into another
 * workspace is decided there. Rows carry no tenant: a person in two workspaces sees their own rows in both.
 *
 * READ-ONLY, with no detail page (`showable: false`): every field is already in the row, as `tokens` rules for its own
 * shared table. Marking read is ticket 10's. It is seated by the HOST in its `user` realm (whose rows these are) and its
 * `tenant` realm (where the tenant socket reaches them), exactly as `tokens` is; the operator realm gets no seat.
 */
#[ParticleResource(
    key: 'notifications',
    label: 'Notifications',
    backing: OwnStoredNotificationsBacking::class,
    input: false,
    readOnly: true,
    showable: false,
    // Any signed-in actor may read, through the backing's row boundary: the rows are always the actor's own notifiable's.
    readPolicy: AuthenticatedReadPolicy::class,
    // ADR-0230 Public Read: none. A guest has no notifiable, and the list is the signed-in reader's own.
    publicRead: null,
    summaryProvider: StoredNotificationSummaryProvider::class,
)]
#[TypeScript]
class StoredNotificationData extends Data
{
    public function __construct(
        #[NotInList]
        #[Description('The stored notification id (a UUID).')]
        public string $id,
        #[Column(label: 'Notification', sort: 0)]
        #[Description('The notification as text for this reader: a known kind rendered in their language, or the stored text unchanged.')]
        public ?string $text,
        #[Column(label: 'Kind', sort: 1)]
        #[Description('The producer\'s kind, an open string; null for a notification that stored none.')]
        public ?string $kind,
        #[Column(label: 'Link', sort: 2)]
        #[Description('Where the notification points, exactly as stored; null when it stored no link.')]
        public ?string $link,
        #[Column(label: 'Unread', sort: 3)]
        #[Description('True until the reader marks it read.')]
        public bool $unread,
        #[Column(label: 'Received', sort: 4)]
        #[Sortable(column: 'created_at', default: true, direction: 'desc')]
        #[Description('When it was stored, ISO-8601; null for a row stored without a timestamp. Newest first is the default order.')]
        public ?string $createdAt,
    ) {}

    /** One stored row, as its reader reads it. */
    public static function project(Model $notification): self
    {
        $data = $notification instanceof DatabaseNotification ? (array) $notification->data : (array) json_decode((string) $notification->getAttribute('data'), true);
        $legacy = self::storedText($data);

        return new self(
            id: (string) $notification->getKey(),
            text: app(MessagePresenters::class)->present('notification', $data, $data['messageReference'] ?? null, $legacy)->text,
            kind: isset($data['kind']) && is_string($data['kind']) ? $data['kind'] : null,
            link: self::storedLink($data),
            unread: $notification->getAttribute('read_at') === null,
            // A row stored without a timestamp is listed with a null createdAt, not skipped and never an empty string.
            createdAt: $notification->getAttribute('created_at')?->toIso8601String(),
        );
    }

    /**
     * The stored words, unchanged: a producer's legacy `message`, else an `x-beam-notify` row's authored `subject`, else
     * its `body` (a form that authored no subject).
     *
     * @param  array<string, mixed>  $data
     */
    private static function storedText(array $data): ?string
    {
        foreach (['message', 'subject', 'body'] as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                return $data[$field];
            }
        }

        return null;
    }

    /**
     * The stored link, as stored: `action_url`, else an existing commerce row's `cta_url`.
     *
     * @param  array<string, mixed>  $data
     */
    private static function storedLink(array $data): ?string
    {
        foreach (['action_url', 'cta_url'] as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                return $data[$field];
            }
        }

        return null;
    }
}
