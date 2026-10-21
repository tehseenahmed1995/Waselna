<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

/**
 * A Restaurant_Operator's request to update the profile of its one Restaurant
 * (Requirement 32.1).
 *
 * The owning Restaurant comes from the authenticated actor, never the body. The
 * fields are the profile fields task 7.6 owns — the Restaurant name, an optional
 * description, the ISO 4217 currency code, the tax rate in basis points, the base
 * Delivery_Fee in minor units, the cuisine tags, and the IANA time zone the
 * Restaurant's Opening_Hours are read against (Requirement 32.1). The
 * Restaurant_Status lifecycle is a separate concern owned by task 9.1 and is not
 * carried here.
 *
 * The character and count bounds of Requirement 32.1 — a name of 1 to 150
 * characters, a description of 0 to 2,000, at most 10 cuisine tags each of 1 to
 * 50 characters — are shape rules the Form_Request enforces; the IANA time zone
 * validity that the Form_Request cannot express is enforced by the service.
 */
final readonly class RestaurantProfileCommand
{
    /**
     * @param  list<string>  $cuisineTags
     */
    public function __construct(
        public string $restaurantId,
        public string $name,
        public ?string $description,
        public string $currency,
        public int $taxRateBasisPoints,
        public int $baseDeliveryFeeMinor,
        public array $cuisineTags,
        public string $timeZone,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the validated request payload
     */
    public static function fromValidated(string $restaurantId, array $input): self
    {
        /** @var list<string> $tags */
        $tags = array_values(array_map(
            static fn (mixed $tag): string => trim((string) $tag),
            (array) ($input['cuisine_tags'] ?? []),
        ));

        $description = $input['description'] ?? null;

        return new self(
            restaurantId: $restaurantId,
            name: trim((string) $input['name']),
            description: $description === null ? null : trim((string) $description),
            currency: strtoupper(trim((string) $input['currency'])),
            taxRateBasisPoints: (int) $input['tax_rate_bp'],
            baseDeliveryFeeMinor: (int) $input['base_delivery_fee'],
            cuisineTags: $tags,
            timeZone: trim((string) $input['time_zone']),
        );
    }
}
