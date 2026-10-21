<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Catalog\Models\Restaurant;

/**
 * The module-owned read view of one Restaurant returned by the lifecycle and
 * admin operations (design "Components and Interfaces": boundary methods return
 * module-owned DTOs, never Eloquent models).
 *
 * A lifecycle transition returns the Restaurant with its new Restaurant_Status
 * so the caller observes the committed value without a re-read.
 */
final readonly class RestaurantView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public string $status,
        public string $currency,
    ) {}

    public static function fromModel(Restaurant $restaurant): self
    {
        return new self(
            id: $restaurant->id,
            name: $restaurant->name,
            description: $restaurant->description,
            status: $restaurant->status,
            currency: $restaurant->currency,
        );
    }

    /**
     * @return array{id: string, name: string, description: ?string, status: string, currency: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'currency' => $this->currency,
        ];
    }
}
