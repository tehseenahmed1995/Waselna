<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * A Restaurant_Operator's request to create or update a Modifier_Group of a
 * Menu_Item (Requirements 11.4, 34.3). The owning Restaurant comes from the
 * authenticated actor; the Menu_Item and, on update, the Modifier_Group are
 * validated against that Restaurant by the service.
 */
final readonly class ModifierGroupCommand
{
    public function __construct(
        public string $restaurantId,
        public string $menuItemId,
        public string $name,
        public int $minSelections,
        public int $maxSelections,
        public int $displayOrder,
        public ?string $groupId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the validated request payload
     */
    public static function fromValidated(string $restaurantId, string $menuItemId, array $input, ?string $groupId = null): self
    {
        return new self(
            restaurantId: $restaurantId,
            menuItemId: $menuItemId,
            name: trim((string) $input['name']),
            minSelections: (int) $input['min_selections'],
            maxSelections: (int) $input['max_selections'],
            displayOrder: (int) $input['display_order'],
            groupId: $groupId,
        );
    }
}
