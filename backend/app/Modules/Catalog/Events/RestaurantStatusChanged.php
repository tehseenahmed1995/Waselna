<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use App\Shared\Support\Clock;
use DateTimeImmutable;

/**
 * The `restaurant.status_changed` domain event, published by Catalog after a
 * Restaurant_Status transition commits (Requirements 59.7, 59.9, 18.1, 18.2).
 *
 * It carries the Restaurant identifier, the previous and new Restaurant_Status,
 * the acting Platform user's identifier, and the UTC timestamp of the change
 * read from the injected {@see Clock}. The previous and new status are both
 * carried because the Audit_Entry of Requirement 59.9 records exactly that pair,
 * and because a subscriber reacting to publication or suspension (search and
 * Menu read-model invalidation, Requirement 59.7) can tell what changed without
 * a re-read.
 *
 * The event is collected during the transaction and dispatched only after the
 * commit, so a rolled-back transition publishes nothing (design "post-commit
 * events"). It is immutable: the facts of a committed change cannot be edited
 * after the fact.
 */
final readonly class RestaurantStatusChanged
{
    public function __construct(
        public string $restaurantId,
        public string $fromStatus,
        public string $toStatus,
        public string $actorId,
        public DateTimeImmutable $occurredAt,
    ) {}

    /**
     * The event name as it appears on the wire and in the design's event
     * catalogue.
     */
    public const NAME = 'restaurant.status_changed';

    /**
     * The documented payload shape, UTC timestamp in RFC 3339 with a `Z` offset.
     *
     * @return array{
     *     event: string,
     *     restaurant_id: string,
     *     from_status: string,
     *     to_status: string,
     *     actor_id: string,
     *     occurred_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'event' => self::NAME,
            'restaurant_id' => $this->restaurantId,
            'from_status' => $this->fromStatus,
            'to_status' => $this->toStatus,
            'actor_id' => $this->actorId,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
