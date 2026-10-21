<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Catalog\Models\ModifierGroup;

/**
 * The management projection of a Modifier_Group (Requirements 11.4, 34.3).
 */
final readonly class ModifierGroupView
{
    public function __construct(
        public string $id,
        public string $menuItemId,
        public string $name,
        public int $minSelections,
        public int $maxSelections,
        public int $displayOrder,
    ) {}

    public static function fromModel(ModifierGroup $group): self
    {
        return new self(
            id: $group->id,
            menuItemId: $group->menu_item_id,
            name: $group->name,
            minSelections: $group->min_selections,
            maxSelections: $group->max_selections,
            displayOrder: $group->display_order,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     menu_item_id: string,
     *     name: string,
     *     min_selections: int,
     *     max_selections: int,
     *     display_order: int
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'menu_item_id' => $this->menuItemId,
            'name' => $this->name,
            'min_selections' => $this->minSelections,
            'max_selections' => $this->maxSelections,
            'display_order' => $this->displayOrder,
        ];
    }
}
