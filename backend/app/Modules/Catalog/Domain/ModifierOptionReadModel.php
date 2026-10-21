<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * The Customer-facing read-model projection of a Modifier_Option
 * (Requirements 4.2). Its Price_Delta is a signed integer number of minor
 * currency units; the currency code lives on the owning {@see MenuReadModel}.
 *
 * Immutable and framework-free so the same value serves both the live
 * repository build and the cache round-trip: {@see self::toArray()} is the exact
 * shape stored in `menu:v1:{restaurantId}` and {@see self::fromArray()} rebuilds
 * it, which is what keeps a cached read byte-identical to an uncached one
 * (Requirement 4.5, Property 13).
 */
final readonly class ModifierOptionReadModel
{
    public function __construct(
        public string $id,
        public string $name,
        public int $priceDeltaMinor,
        public int $displayOrder,
    ) {}

    /**
     * @return array{id: string, name: string, price_delta_minor: int, display_order: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price_delta_minor' => $this->priceDeltaMinor,
            'display_order' => $this->displayOrder,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  a decoded cache entry
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            priceDeltaMinor: (int) $data['price_delta_minor'],
            displayOrder: (int) $data['display_order'],
        );
    }
}
