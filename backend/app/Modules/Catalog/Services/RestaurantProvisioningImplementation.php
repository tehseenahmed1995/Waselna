<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Boundary\RestaurantProvisioning;
use App\Modules\Catalog\Models\Restaurant;
use App\Modules\Catalog\Models\RestaurantBranch;
use App\Shared\Enums\Currency;
use App\Shared\Enums\RestaurantStatus;
use App\Shared\Support\Clock;
use App\Shared\Support\IdGenerator;

/**
 * Creates the Catalog-owned rows for a self-registering Restaurant_Operator: a
 * draft Restaurant plus one main Restaurant_Branch. Runs inside the Auth
 * registration transaction (see {@see RestaurantProvisioning}).
 */
final class RestaurantProvisioningImplementation implements RestaurantProvisioning
{
    public function __construct(
        private readonly IdGenerator $ids,
        private readonly Clock $clock,
    ) {}

    public function provisionOperatorRestaurant(string $restaurantName): array
    {
        $now = $this->clock->nowUtc();
        $name = trim($restaurantName) !== '' ? trim($restaurantName) : 'My Restaurant';

        /** @var Restaurant $restaurant */
        $restaurant = Restaurant::query()->create([
            'id' => $this->ids->ulid(),
            'name' => $name,
            'description' => null,
            // Draft: the operator completes the profile and menu, then publishes.
            'status' => RestaurantStatus::Draft->value,
            'currency' => Currency::USD->value,
            'tax_rate_bp' => 0,
            'base_delivery_fee' => 0,
            'rating' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var RestaurantBranch $branch */
        $branch = RestaurantBranch::query()->create([
            'id' => $this->ids->ulid(),
            'restaurant_id' => $restaurant->id,
            'name' => $name.' — Main Branch',
            'address' => 'Address pending',
            'latitude' => 24.7136,
            'longitude' => 46.6753,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'restaurant_id' => $restaurant->id,
            'branch_id' => $branch->id,
        ];
    }
}
