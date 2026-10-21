<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Events;

use App\Shared\Support\Clock;
use DateTimeImmutable;

/**
 * The `menu_item.availability_changed` domain event (Requirements 34.8, 6.4).
 *
 * Published by Catalog when a Restaurant_Operator or Restaurant_Staff sets the
 * availability flag of a Menu_Item or a Product_Variant to false. It carries
 * exactly the four documented fields: the Menu_Item identifier, the optional
 * Product_Variant identifier (null when the whole Menu_Item was toggled), the
 * new availability flag, and the UTC timestamp of the change read from the
 * injected {@see Clock}.
 *
 * The event is the single integration seam named by Requirement 39: Cart and
 * any later subscriber react to it without Catalog knowing them (the Observer
 * pattern of Requirement 58.4). The `restaurantId` is included so a subscriber —
 * and Catalog's own read-model invalidation — can scope its reaction to the one
 * Restaurant without a second lookup. It is immutable: the facts of a change
 * that has already committed cannot be edited after the fact.
 */
final readonly class MenuItemAvailabilityChanged
{
    public function __construct(
        public string $restaurantId,
        public string $menuItemId,
        public ?string $productVariantId,
        public bool $isAvailable,
        public DateTimeImmutable $occurredAt,
    ) {}

    /**
     * The event name as it appears on the wire and in the design's event
     * catalogue (Requirement 34.8).
     */
    public const NAME = 'menu_item.availability_changed';

    /**
     * The documented payload shape, UTC timestamp in RFC 3339 with a `Z` offset.
     *
     * @return array{
     *     event: string,
     *     restaurant_id: string,
     *     menu_item_id: string,
     *     product_variant_id: ?string,
     *     is_available: bool,
     *     occurred_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'event' => self::NAME,
            'restaurant_id' => $this->restaurantId,
            'menu_item_id' => $this->menuItemId,
            'product_variant_id' => $this->productVariantId,
            'is_available' => $this->isAvailable,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
