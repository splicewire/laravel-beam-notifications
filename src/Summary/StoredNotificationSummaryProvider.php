<?php

namespace Splicewire\Beam\Notifications\Summary;

use Schemastud\Frame\Contracts\ResourceSummaryProvider;
use Schemastud\Frame\Data\SummaryFigureData;
use Schemastud\Frame\Data\SummaryResponseData;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\ScopedIndexQuery;

/**
 * The `notifications` resource's summary: how many of the reader's stored notifications are unread, and how many there
 * are (attention-surfaces 09). Minted with `splicewire:beam:make:particle-resource --summary` and moved into this
 * package with its resource.
 *
 * Both figures count through {@see ScopedIndexQuery::forAggregate()}, the SAME builder the list reads, which is the
 * resource's backing: the notifiable ticket 08's port resolves for this actor. So the summary answers the resolved
 * notifiable's unread count and cannot disagree with the list about whose rows they are. An unresolved actor counts
 * zero, because its backing matches no row. `forAggregate()` clears the declared newest-first order, which Postgres
 * would refuse beside an aggregate.
 */
class StoredNotificationSummaryProvider implements ResourceSummaryProvider
{
    public function __construct(private ScopedIndexQuery $query) {}

    public function summary(ResourceDefinition $resource): ?SummaryResponseData
    {
        if (! $this->query->queryable($resource)) {
            return null;
        }

        $rows = $this->query->forAggregate($resource);
        $label = $resource->resolvedLabel();

        return new SummaryResponseData(
            key: $resource->key,
            label: $label,
            icon: $resource->nav->icon,
            figures: [
                new SummaryFigureData(key: 'unread', label: 'Unread', value: (clone $rows)->whereNull('read_at')->count()),
                new SummaryFigureData(key: 'total', label: $label, value: (clone $rows)->count()),
            ],
            overview: null,
        );
    }
}
