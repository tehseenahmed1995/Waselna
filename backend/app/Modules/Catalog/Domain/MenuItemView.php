<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Catalog\Models\MenuItem;

/**
 * The management projection of a Menu_Item (Requirement 11.2). The Price is
 * carried as an integer number of minor currency units; the currency code lives
 * on the owning Restaurant.
 */
final readonly class MenuItemView
{
    public function __construct(
        public string $id,
        public string $restaurantId,
        public string $categoryId,
        public string $name,
        public ?string $description,
        public int $priceMinor,
        public bool $isAvailable,
        public int $displayOrder,
        public bool $archived,
    ) {}

    public static function fromModel(MenuItem $item): self
    {
        return new self(
            id: $item->id,
            restaurantId: $item->restaurant_id,
            categoryId: $item->category_id,
            name: $item->name,
            description: $item->description,
            priceMinor: $item->price_minor,
            isAvailable: $item->is_available,
            displayOrder: $item->display_order,
            archived: $item->archived_at !== null,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     restaurant_id: string,
     *     category_id: string,
     *     name: string,
     *     description: ?string,
     *     price_minor: int,
     *     is_available: bool,
     *     display_order: int,
     *     archived: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurantId,
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'description' => $this->description,
            'price_minor' => $this->priceMinor,
            'is_available' => $this->isAvailable,
            'display_order' => $this->displayOrder,
            'archived' => $this->archived,
        ];
    }
}
