<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Catalog\Models\ProductVariant;

/**
 * The management projection of a Product_Variant (Requirement 34.1).
 */
final readonly class ProductVariantView
{
    public function __construct(
        public string $id,
        public string $menuItemId,
        public string $name,
        public int $priceMinor,
        public bool $isAvailable,
        public int $displayOrder,
    ) {}

    public static function fromModel(ProductVariant $variant): self
    {
        return new self(
            id: $variant->id,
            menuItemId: $variant->menu_item_id,
            name: $variant->name,
            priceMinor: $variant->price_minor,
            isAvailable: $variant->is_available,
            displayOrder: $variant->display_order,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     menu_item_id: string,
     *     name: string,
     *     price_minor: int,
     *     is_available: bool,
     *     display_order: int
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'menu_item_id' => $this->menuItemId,
            'name' => $this->name,
            'price_minor' => $this->priceMinor,
            'is_available' => $this->isAvailable,
            'display_order' => $this->displayOrder,
        ];
    }
}
