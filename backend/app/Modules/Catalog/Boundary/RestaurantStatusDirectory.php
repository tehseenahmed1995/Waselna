<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Boundary;

/**
 * The Catalog module's published boundary for reading a Restaurant's lifecycle
 * status, consumed by the Order module's checkout path (Requirements 18.2,
 * 59.5).
 *
 * Suspension has two visible effects. A suspended Restaurant disappears from
 * Customer-facing results — enforced inside Catalog, whose discovery queries
 * return only `published` Restaurants — and checkout naming a suspended
 * Restaurant is rejected with `409 restaurant_suspended` (Requirement 18.2). The
 * Order module owns the checkout transaction but not the Restaurant tables, so
 * it reaches the status fact through this seam rather than reading
 * `restaurants` directly (module ownership; Requirement 22.8). The boundary
 * returns only primitives, so Catalog can later move behind a network call
 * without an Order change.
 */
interface RestaurantStatusDirectory
{
    /**
     * The current Restaurant_Status of $restaurantId, or null where no
     * Restaurant matches the identifier. One of `draft`, `pending_review`,
     * `published`, `suspended`.
     */
    public function statusOf(string $restaurantId): ?string;

    /**
     * True when $restaurantId exists and its Restaurant_Status is `suspended`.
     * The Order checkout path tests this to reject a suspended Restaurant with
     * `restaurant_suspended` (Requirement 18.2). A missing Restaurant yields
     * false; the checkout path raises its own non-disclosing not-found for that
     * case.
     */
    public function isSuspended(string $restaurantId): bool;
}
