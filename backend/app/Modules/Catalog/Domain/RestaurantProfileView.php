<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Catalog\Models\Restaurant;

/**
 * The management projection of a Restaurant profile (Requirement 32.1).
 *
 * Carries the profile fields task 7.6 owns plus the Restaurant_Status, read-only
 * here, so a Restaurant_Operator viewing the profile sees the current status
 * without this task owning the transitions that change it (task 9.1).
 */
final readonly class RestaurantProfileView
{
    /**
     * @param  list<string>  $cuisineTags
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public string $status,
        public string $currency,
        public int $taxRateBasisPoints,
        public int $baseDeliveryFeeMinor,
        public string $timeZone,
        public array $cuisineTags,
    ) {}

    /**
     * @param  list<string>  $cuisineTags  the Restaurant's cuisine tags in a
     *                                     stable order, read alongside the row
     */
    public static function fromModel(Restaurant $restaurant, array $cuisineTags): self
    {
        return new self(
            id: $restaurant->id,
            name: $restaurant->name,
            description: $restaurant->description,
            status: $restaurant->status,
            currency: (string) $restaurant->currency,
            taxRateBasisPoints: (int) $restaurant->tax_rate_bp,
            baseDeliveryFeeMinor: (int) $restaurant->base_delivery_fee,
            timeZone: (string) $restaurant->time_zone,
            cuisineTags: $cuisineTags,
        );
    }

    /**
     * @return array{id: string, name: string, description: ?string, status: string, currency: string, tax_rate_bp: int, base_delivery_fee: int, time_zone: string, cuisine_tags: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'currency' => $this->currency,
            'tax_rate_bp' => $this->taxRateBasisPoints,
            'base_delivery_fee' => $this->baseDeliveryFeeMinor,
            'time_zone' => $this->timeZone,
            'cuisine_tags' => $this->cuisineTags,
        ];
    }
}
