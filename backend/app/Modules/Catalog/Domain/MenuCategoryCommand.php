<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * A Restaurant_Operator's request to create or update a Menu_Category
 * (Requirement 11.1). The owning Restaurant comes from the authenticated actor,
 * never the body; on update the category identifier is validated against that
 * Restaurant by the service.
 */
final readonly class MenuCategoryCommand
{
    public function __construct(
        public string $restaurantId,
        public string $name,
        public int $displayOrder,
        public ?string $categoryId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the validated request payload
     */
    public static function fromValidated(string $restaurantId, array $input, ?string $categoryId = null): self
    {
        return new self(
            restaurantId: $restaurantId,
            name: trim((string) $input['name']),
            displayOrder: (int) $input['display_order'],
            categoryId: $categoryId,
        );
    }
}
