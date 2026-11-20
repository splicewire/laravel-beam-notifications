<?php

namespace Splicewire\Beam\Notifications\Localization;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why this package refused a reader (attention-surfaces 10). Each case's value is the stable `code` a client receives
 * beside the localized message; the English is declared here and its key is derived.
 */
enum NotificationError: string implements CodedError
{
    use IsCodedError;

    /** The request's actor resolves to no notifiable person, so there are no notifications of theirs to change. */
    #[Message('There are no notifications here for you to mark read.')]
    case ReaderUnresolved = 'notification_reader_unresolved';
}
