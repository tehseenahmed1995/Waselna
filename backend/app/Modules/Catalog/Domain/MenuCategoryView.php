<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Catalog\Models\MenuCategory;

/**
 * The management projection of a Menu_Category (Requirement 11.1).
 */
final readonly class MenuCategoryView
{
    public function __construct(
        public string $id,
        public string $restaurantId,
        public string $name,
        public int $displayOrder,
        public bool $archived,
    ) {}

    public static function fromModel(MenuCategory $category): self
    {
        return new self(
            id: $category->id,
            restaurantId: $category->restaurant_id,
            name: $category->name,
            displayOrder: $category->display_order,
            archived: $category->archived_at !== null,
        );
    }

    /**
     * @return array{id: string, restaurant_id: string, name: string, display_order: int, archived: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'restaurant_id' => $this->restaurantId,
            'name' => $this->name,
            'display_order' => $this->displayOrder,
            'archived' => $this->archived,
        ];
    }
}
