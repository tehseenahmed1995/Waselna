<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Repositories;

use App\Modules\Catalog\Models\CuisineTag;
use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Catalog\Models\RestaurantBranch;
use App\Modules\Catalog\Services\RestaurantManagementServiceImplementation;
use App\Shared\Support\IdGenerator;

/**
 * The one place that queries the `restaurants`, `restaurant_cuisine_tags`, and
 * `restaurant_branches` tables for profile and Restaurant_Branch management
 * (Backend_Layer_Chain, task 7.6; Requirements 32.1, 32.2, 32.3, 32.11, 32.13).
 *
 * Concrete and Eloquent-backed, following the repository deviation the design
 * records: the {@see RestaurantManagementServiceImplementation}
 * holds the rules, this holds the queries. Every write is expected to run inside
 * the service's transaction with the parent Restaurant row already locked, so
 * the repository itself opens no transaction and the count checks it answers
 * cannot be raced.
 */
final class RestaurantManagementRepository
{
    public function __construct(private readonly IdGenerator $ids) {}

    /**
     * Locks the Restaurant row for the duration of the surrounding transaction
     * and returns it, or null where no such Restaurant exists (Requirement
     * 32.13). The lock serialises a concurrent Restaurant_Branch creation
     * against the same Restaurant so the 50-branch count cannot be raced.
     */
    public function lockRestaurant(string $restaurantId): ?Restaurant
    {
        /** @var Restaurant|null $restaurant */
        $restaurant = Restaurant::query()
            ->where('id', $restaurantId)
            ->lockForUpdate()
            ->first();

        return $restaurant;
    }

    public function saveRestaurant(Restaurant $restaurant): void
    {
        $restaurant->save();
    }

    /**
     * The Restaurant's cuisine tags in ascending tag order, so the stored order
     * is stable across reads.
     *
     * @return list<string>
     */
    public function cuisineTagsFor(string $restaurantId): array
    {
        /** @var list<string> $tags */
        $tags = CuisineTag::query()
            ->where('restaurant_id', $restaurantId)
            ->orderBy('tag')
            ->pluck('tag')
            ->all();

        return $tags;
    }

    /**
     * Replaces the Restaurant's complete set of cuisine tags with $tags:
     * every existing tag is removed and the submitted tags inserted
     * (Requirement 32.1). Run inside the service transaction. Duplicate tags are
     * collapsed so the `UNIQUE(restaurant_id, tag)` constraint is never
     * challenged.
     *
     * @param  list<string>  $tags
     */
    public function replaceCuisineTags(string $restaurantId, array $tags): void
    {
        CuisineTag::query()->where('restaurant_id', $restaurantId)->delete();

        foreach (array_values(array_unique($tags)) as $tag) {
            CuisineTag::query()->create([
                'id' => $this->ids->ulid(),
                'restaurant_id' => $restaurantId,
                'tag' => $tag,
            ]);
        }
    }

    /**
     * The number of Restaurant_Branches of a Restaurant, counting inactive ones
     * toward the 50-branch bound (Requirement 32.3). Read under the parent lock.
     */
    public function countBranches(string $restaurantId): int
    {
        return RestaurantBranch::query()
            ->where('restaurant_id', $restaurantId)
            ->count();
    }

    /**
     * The number of Restaurant_Branches of a Restaurant whose active flag is
     * true, used to decide whether deactivating one would leave a published
     * Restaurant with none (Requirement 32.3). Read under the parent lock.
     */
    public function countActiveBranches(string $restaurantId): int
    {
        return RestaurantBranch::query()
            ->where('restaurant_id', $restaurantId)
            ->where('is_active', true)
            ->count();
    }

    /**
     * A Restaurant_Branch scoped to its owning Restaurant, or null where the
     * identifier matches no Restaurant_Branch of that Restaurant. Scoping the
     * read to the Restaurant is what lets the service answer the non-disclosing
     * 404 without revealing another Restaurant's Restaurant_Branch.
     */
    public function findBranchOwned(string $restaurantId, string $branchId): ?RestaurantBranch
    {
        /** @var RestaurantBranch|null $branch */
        $branch = RestaurantBranch::query()
            ->where('restaurant_id', $restaurantId)
            ->where('id', $branchId)
            ->first();

        return $branch;
    }

    public function saveBranch(RestaurantBranch $branch): void
    {
        $branch->save();
    }

    public function newBranchId(): string
    {
        return $this->ids->ulid();
    }
}
