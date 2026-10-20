<?php

namespace Splicewire\Beam\Notifications\Support;

use Illuminate\Support\Str;
use Splicewire\Beam\Notifications\Notifications\BeamNotification;
use Throwable;

/**
 * What a delivery was, in words: the name the operator ledger shows in its "Notification" column
 * instead of the class that was sent (owner re-walk WS-08 — a lead's only trace in the admin read
 * `Splicewire\Beam\Notifications\Notifications\BeamNotification`).
 *
 * In order:
 *  1. The generic {@see BeamNotification}: the subject its `x-beam-notify` declared, rendered over the
 *     context it was sent with, which is the subject line the recipient's mail carried. With no declared
 *     subject, the form's own declared title ("New submission: Request access"), then the mail's own
 *     default ("New submission").
 *  2. Any other notification: its short class name in words, a trailing "Notification" dropped, so
 *     `Illuminate\Auth\Notifications\ResetPassword` reads "Reset password". The last resort, and never a
 *     namespace.
 *
 * The stored instance is read INERTLY: unserialized with `allowed_classes => false`, so no class is
 * instantiated, no model is restored (no query per row), and no magic method runs on ledger content.
 *
 * The serialized blob never travels, but these words can carry what the declared subject interpolates: a
 * subject of `New access request from {{ payload.name }}` renders over the stored context, so that lead's
 * name is on the list for everyone who may read the ledger, exactly as it was in the mail's subject line.
 * A subject that must stay off the list should not interpolate the payload.
 */
final class DeliveryLabel
{
    /** Longer subjects are cut, so one verbose template cannot stretch the ledger's list. */
    public const MAX_LENGTH = 120;

    /** The mail's own subject when the keyword declares none ({@see BeamNotification::toMail()}). */
    public const DEFAULT_SUBMISSION_SUBJECT = 'New submission';

    public static function for(string $notificationType, ?string $serialized): string
    {
        if (self::isGenericBeamNotification($notificationType)) {
            return self::forBeamNotification(self::storedFields($serialized));
        }

        return self::humanize($notificationType);
    }

    /**
     * @param  array<array-key, mixed>  $fields  The stored instance's public properties.
     */
    private static function forBeamNotification(array $fields): string
    {
        $context = is_array($fields['context'] ?? null) ? $fields['context'] : [];
        $subject = $fields['subject'] ?? null;

        if (is_string($subject)) {
            $rendered = self::clean(Interpolator::render($subject, $context));

            if ($rendered !== '') {
                return $rendered;
            }
        }

        $title = data_get($context, 'schema.title');

        if (is_string($title) && ($title = self::clean($title)) !== '') {
            return self::DEFAULT_SUBMISSION_SUBJECT.': '.$title;
        }

        return self::DEFAULT_SUBMISSION_SUBJECT;
    }

    /**
     * The stored notification's properties, without waking it: an unknown class unserializes as
     * `__PHP_Incomplete_Class`, whose array cast still carries its public properties by name. A blob
     * that will not parse is no label source rather than an error on a read-only list.
     *
     * @return array<array-key, mixed>
     */
    private static function storedFields(?string $serialized): array
    {
        if ($serialized === null || $serialized === '') {
            return [];
        }

        try {
            $value = @unserialize($serialized, ['allowed_classes' => false]);
        } catch (Throwable) {
            return [];
        }

        return is_object($value) || is_array($value) ? (array) $value : [];
    }

    private static function isGenericBeamNotification(string $notificationType): bool
    {
        return $notificationType !== ''
            && ltrim($notificationType, '\\') === BeamNotification::class;
    }

    /**
     * `App\Notifications\InvoicePaidNotification` → "Invoice paid"; `SMSAlert` → "SMS alert". Acronyms
     * stay whole and upper-case; every other word is lower-cased after the first.
     */
    private static function humanize(string $notificationType): string
    {
        if (str_contains($notificationType, '@anonymous')) {
            return 'Notification';
        }

        $short = Str::afterLast(trim($notificationType, '\\'), '\\');
        $stem = preg_replace('/Notification$/', '', $short);
        $stem = $stem === '' || $stem === null ? $short : $stem;

        $words = preg_split(
            '/[\s_\-]+|(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/',
            $stem,
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        if ($words === []) {
            return 'Notification';
        }

        $words = array_map(
            static fn (string $word): string => mb_strlen($word) > 1 && mb_strtoupper($word) === $word
                ? $word
                : mb_strtolower($word),
            $words,
        );

        return self::clean(Str::ucfirst(implode(' ', $words)));
    }

    private static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Str::limit($text, self::MAX_LENGTH - 1, '…');
    }
}
