<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Catalog\Models\ModifierOption;

/**
 * The management projection of a Modifier_Option (Requirement 11.2).
 */
final readonly class ModifierOptionView
{
    public function __construct(
        public string $id,
        public string $modifierGroupId,
        public string $name,
        public int $priceDeltaMinor,
        public int $displayOrder,
    ) {}

    public static function fromModel(ModifierOption $option): self
    {
        return new self(
            id: $option->id,
            modifierGroupId: $option->modifier_group_id,
            name: $option->name,
            priceDeltaMinor: $option->price_delta_minor,
            displayOrder: $option->display_order,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     modifier_group_id: string,
     *     name: string,
     *     price_delta_minor: int,
     *     display_order: int
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'modifier_group_id' => $this->modifierGroupId,
            'name' => $this->name,
            'price_delta_minor' => $this->priceDeltaMinor,
            'display_order' => $this->displayOrder,
        ];
    }
}
