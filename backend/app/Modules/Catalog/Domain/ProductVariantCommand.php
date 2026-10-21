<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * A Restaurant_Operator's request to create or update a Product_Variant of a
 * Menu_Item (Requirement 34.1). The owning Restaurant comes from the
 * authenticated actor; the Menu_Item and, on update, the Product_Variant are
 * validated against that Restaurant by the service.
 */
final readonly class ProductVariantCommand
{
    public function __construct(
        public string $restaurantId,
        public string $menuItemId,
        public string $name,
        public int $priceMinor,
        public bool $isAvailable,
        public int $displayOrder,
        public ?string $variantId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the validated request payload
     */
    public static function fromValidated(string $restaurantId, string $menuItemId, array $input, ?string $variantId = null): self
    {
        return new self(
            restaurantId: $restaurantId,
            menuItemId: $menuItemId,
            name: trim((string) $input['name']),
            priceMinor: (int) $input['price_minor'],
            isAvailable: (bool) ($input['is_available'] ?? true),
            displayOrder: (int) $input['display_order'],
            variantId: $variantId,
        );
    }
}
