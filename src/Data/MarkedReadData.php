<?php

namespace Splicewire\Beam\Notifications\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What mark-all-read answers: how many of the reader's unread notifications it marked read (attention-surfaces 10). */
#[TypeScript]
class MarkedReadData extends Data
{
    public function __construct(
        #[Description('How many of your unread notifications were marked read; 0 when none were unread.')]
        public int $marked,
    ) {}
}
