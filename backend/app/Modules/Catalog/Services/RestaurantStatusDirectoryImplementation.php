<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Boundary\RestaurantStatusDirectory;
use App\Modules\Catalog\Domain\RestaurantStatus;
use App\Modules\Catalog\Models\Restaurant;

/**
 * The Catalog module's implementation of the {@see RestaurantStatusDirectory}
 * boundary (Requirements 18.2, 59.5).
 *
 * A thin read over the `restaurants` table: it exposes the one status fact the
 * Order checkout path needs so that module need not read a Catalog table. It
 * holds no rule beyond the suspended comparison; the lifecycle rules live in the
 * {@see RestaurantLifecycleServiceImplementation}.
 */
final class RestaurantStatusDirectoryImplementation implements RestaurantStatusDirectory
{
    public function statusOf(string $restaurantId): ?string
    {
        /** @var Restaurant|null $restaurant */
        $restaurant = Restaurant::query()
            ->whereKey($restaurantId)
            ->first(['status']);

        return $restaurant?->status;
    }

    public function isSuspended(string $restaurantId): bool
    {
        return $this->statusOf($restaurantId) === RestaurantStatus::SUSPENDED;
    }
}
