<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Repositories;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Invalidates the Catalog read-model cache entries affected by an availability
 * change (Requirements 34.7, 4.6, Property 13).
 *
 * Two kinds of key are affected when a Menu_Item or Product_Variant availability
 * flag changes:
 *
 *  - the Menu read model `menu:v1:{restaurantId}`, which is a single, directly
 *    addressable key — so it is forgotten outright;
 *  - the Restaurant search pages `search:v1:{hash(query)}:{cursor}`, whose keys
 *    depend on the query and cursor and so cannot be enumerated. Rather than
 *    scan the Cache_Store, each search page is stamped with the current search
 *    version for its Restaurant; bumping `search:v1:ver:{restaurantId}` makes
 *    every previously cached page for that Restaurant unreachable in O(1),
 *    which the search read model (task 9.4) folds into its key.
 *
 * Lives in the repositories layer with the other Cache_Store and query
 * collaborators (the module's documented repository deviation): the Service
 * holds the availability rule, this holds the Cache_Store writes. Invalidation
 * runs after the write commits and well inside the 5-second bound of
 * Requirement 34.7, because forgetting a key and incrementing a counter are
 * single Cache_Store round-trips.
 */
final class MenuCacheInvalidator
{
    /** The Menu read-model key prefix (design "Cache keys" table). */
    private const MENU_PREFIX = 'menu:v1:';

    /** The per-Restaurant search version-stamp prefix. */
    private const SEARCH_VERSION_PREFIX = 'search:v1:ver:';

    public function __construct(private readonly Cache $cache) {}

    /**
     * Forgets the Menu read model for the Restaurant and bumps the Restaurant's
     * search version so every cached search page referencing it is superseded.
     */
    public function invalidateForRestaurant(string $restaurantId): void
    {
        $this->cache->forget(self::MENU_PREFIX.$restaurantId);

        $versionKey = self::SEARCH_VERSION_PREFIX.$restaurantId;

        // `increment` creates the counter at 1 on first use for the array and
        // Redis stores; a store that cannot increment a missing key is seeded
        // first so the bump always advances the stamp.
        if ($this->cache->get($versionKey) === null) {
            $this->cache->forever($versionKey, 0);
        }

        $this->cache->increment($versionKey);
    }

    /**
     * The Menu read-model key for a Restaurant, exposed so the read model
     * (task 8.3) and tests address the identical key this invalidator forgets.
     */
    public static function menuKey(string $restaurantId): string
    {
        return self::MENU_PREFIX.$restaurantId;
    }

    /**
     * The search version-stamp key for a Restaurant, exposed so the search read
     * model (task 9.4) folds the identical stamp into its page keys.
     */
    public static function searchVersionKey(string $restaurantId): string
    {
        return self::SEARCH_VERSION_PREFIX.$restaurantId;
    }
}
