<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Repositories;

use App\Modules\Catalog\Models\MenuItem;
use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Catalog\Models\RestaurantBranch;
use App\Modules\Catalog\Services\RestaurantLifecycleServiceImplementation;

/**
 * The one place that queries the Catalog tables for the Restaurant_Status
 * lifecycle (Requirements 18.1, 18.2, 59.3–59.9).
 *
 * Concrete and Eloquent-backed, matching the module's repository deviation: the
 * {@see RestaurantLifecycleServiceImplementation}
 * holds the rules, this holds the queries. Every read is scoped to a single
 * Restaurant; the publication-prerequisite reads cover only this module's own
 * `restaurant_branches` and `menu_items` tables, while the zone prerequisite is
 * answered by the Zone boundary the service injects, so no Zone table is read
 * here (module ownership; Requirement 22.8).
 */
final class RestaurantLifecycleRepository
{
    /**
     * The Restaurant locked for the surrounding transaction, or null where the
     * identifier matches no Restaurant. Locking the row serialises concurrent
     * status transitions so the current status a transition reads cannot be
     * changed under it between the read and the write (design "post-commit
     * events"; Requirement 59.6).
     */
    public function findForUpdate(string $restaurantId): ?Restaurant
    {
        /** @var Restaurant|null $restaurant */
        $restaurant = Restaurant::query()
            ->where('id', $restaurantId)
            ->lockForUpdate()
            ->first();

        return $restaurant;
    }

    /**
     * Persists the Restaurant's updated status and `updated_at`.
     */
    public function save(Restaurant $restaurant): void
    {
        $restaurant->save();
    }

    /**
     * True when the Restaurant holds at least one Restaurant_Branch with an
     * active flag of true (Requirement 59.5, first publication prerequisite).
     */
    public function hasActiveBranch(string $restaurantId): bool
    {
        return RestaurantBranch::query()
            ->where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * True when the Restaurant holds at least one non-archived Menu_Item with an
     * availability flag of true (Requirement 59.5, second publication
     * prerequisite). An archived Menu_Item is excluded, so a Restaurant whose
     * only available item was archived is not publishable.
     */
    public function hasAvailableMenuItem(string $restaurantId): bool
    {
        return MenuItem::query()
            ->where('restaurant_id', $restaurantId)
            ->where('is_available', true)
            ->whereNull('archived_at')
            ->exists();
    }
}
