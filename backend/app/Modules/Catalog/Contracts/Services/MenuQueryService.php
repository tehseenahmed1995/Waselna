<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts\Services;

use App\Modules\Catalog\Domain\MenuReadModel;
use App\Shared\Domain\Exceptions\AuthorizationException;

/**
 * The Customer-facing Menu read for a published Restaurant (Requirement 4).
 *
 * One seam over one implementation, as Requirement 38 asks: the Controller and
 * tests depend on this interface. The implementation serves the composed
 * {@see MenuReadModel} through `CacheOrFallback` — from the
 * `menu:v1:{restaurantId}` cache entry on a hit, from PostgreSQL on a miss while
 * repopulating the entry with a 300-second expiry, and from PostgreSQL while
 * recording the failure when the Cache_Store is unreachable (Requirements 4.5,
 * 4.6, 4.8, 21.2, 21.3, 21.4).
 */
interface MenuQueryService
{
    /**
     * The complete, ordered, availability-preserving Menu read model for the
     * Restaurant whose Restaurant_Status is `published` (Requirement 4.1).
     *
     * A Restaurant with no Menu_Category, or a Menu_Category with no Menu_Item,
     * yields an empty collection at that level rather than an API_Error
     * (Requirement 4.7). A Menu_Item whose availability flag is false is carried
     * in full, marked unavailable (Requirement 4.4).
     *
     * @throws AuthorizationException non-disclosing `restaurant_not_found` where
     *                                the identifier matches no Restaurant whose
     *                                Restaurant_Status is `published`
     *                                (Requirement 4.9); nothing is cached.
     */
    public function menuFor(string $restaurantId): MenuReadModel;
}
