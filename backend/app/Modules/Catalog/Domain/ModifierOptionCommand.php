<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * A Restaurant_Operator's request to create or update a Modifier_Option of a
 * Modifier_Group (Requirement 11.2). The owning Restaurant comes from the
 * authenticated actor; the Modifier_Group and, on update, the Modifier_Option
 * are validated against that Restaurant by the service.
 */
final readonly class ModifierOptionCommand
{
    public function __construct(
        public string $restaurantId,
        public string $modifierGroupId,
        public string $name,
        public int $priceDeltaMinor,
        public int $displayOrder,
        public ?string $optionId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the validated request payload
     */
    public static function fromValidated(string $restaurantId, string $modifierGroupId, array $input, ?string $optionId = null): self
    {
        return new self(
            restaurantId: $restaurantId,
            modifierGroupId: $modifierGroupId,
            name: trim((string) $input['name']),
            priceDeltaMinor: (int) $input['price_delta_minor'],
            displayOrder: (int) $input['display_order'],
            optionId: $optionId,
        );
    }
}
