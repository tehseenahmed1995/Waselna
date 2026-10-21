<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * A Restaurant_Operator's request to create or update a Menu_Item
 * (Requirement 11.2). Carries the name, description, Price in minor units,
 * availability flag, Menu_Category assignment, and display order. The owning
 * Restaurant comes from the authenticated actor; the Menu_Category and, on
 * update, the Menu_Item are validated against that Restaurant by the service.
 */
final readonly class MenuItemCommand
{
    public function __construct(
        public string $restaurantId,
        public string $categoryId,
        public string $name,
        public ?string $description,
        public int $priceMinor,
        public bool $isAvailable,
        public int $displayOrder,
        public ?string $itemId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the validated request payload
     */
    public static function fromValidated(string $restaurantId, array $input, ?string $itemId = null): self
    {
        $description = array_key_exists('description', $input) && $input['description'] !== null
            ? (string) $input['description']
            : null;

        return new self(
            restaurantId: $restaurantId,
            categoryId: (string) $input['category_id'],
            name: trim((string) $input['name']),
            description: $description,
            priceMinor: (int) $input['price_minor'],
            isAvailable: (bool) ($input['is_available'] ?? true),
            displayOrder: (int) $input['display_order'],
            itemId: $itemId,
        );
    }
}
