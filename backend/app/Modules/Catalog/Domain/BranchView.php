<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Catalog\Models\RestaurantBranch;

/**
 * The management projection of a Restaurant_Branch (Requirements 32.2, 32.11).
 *
 * Carries the Restaurant_Branch fields task 7.6 owns: its name, postal address,
 * resolved coordinate pair, applicable time zone, active flag, and the
 * identifiers of the Delivery_Zones currently assigned to it so a
 * Restaurant_Operator sees the Restaurant_Branch's Serviceable_Area composition
 * without a second request (Requirement 32.11).
 */
final readonly class BranchView
{
    /**
     * @param  list<string>  $zoneIds
     */
    public function __construct(
        public string $id,
        public string $restaurantId,
        public string $name,
        public string $address,
        public ?string $latitude,
        public ?string $longitude,
        public ?string $timeZone,
        public bool $isActive,
        public array $zoneIds,
    ) {}

    /**
     * @param  list<string>  $zoneIds  the Delivery_Zone identifiers assigned to
     *                                 the Restaurant_Branch, in a stable order
     */
    public static function fromModel(RestaurantBranch $branch, array $zoneIds): self
    {
        return new self(
            id: $branch->id,
            restaurantId: $branch->restaurant_id,
            name: $branch->name,
            address: $branch->address,
            latitude: $branch->latitude === null ? null : (string) $branch->latitude,
            longitude: $branch->longitude === null ? null : (string) $branch->longitude,
            timeZone: $branch->time_zone,
            isActive: (bool) $branch->is_active,
            zoneIds: $zoneIds,
        );
    }

    /**
     * @return array{id: string, restaurant_id: string, name: string, address: string, latitude: ?string, longitude: ?string, time_zone: ?string, is_active: bool, zone_ids: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurantId,
            'name' => $this->name,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'time_zone' => $this->timeZone,
            'is_active' => $this->isActive,
            'zone_ids' => $this->zoneIds,
        ];
    }
}
