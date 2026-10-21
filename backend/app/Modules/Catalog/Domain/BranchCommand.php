<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Shared\Domain\PostalAddress;

/**
 * A Restaurant_Operator's request to create or update a Restaurant_Branch
 * (Requirements 32.2, 32.12, 32.14).
 *
 * The owning Restaurant comes from the authenticated actor, never the body; on
 * update the Restaurant_Branch identifier is validated against that Restaurant by
 * the service. The address is carried as its raw postal parts so the service can
 * hand them to the Zone module's address resolver, which turns them into the
 * resolved coordinate pair stored on the Restaurant_Branch (Requirement 32.2,
 * 32.14).
 *
 * The optional time zone follows Requirement 32.2: where it is absent the service
 * applies the Restaurant's time zone to the Restaurant_Branch. The active flag is
 * not set here — a newly created Restaurant_Branch is always active
 * (Requirement 32.2), and a change to the flag goes through the dedicated
 * activate/deactivate operation.
 */
final readonly class BranchCommand
{
    public function __construct(
        public string $restaurantId,
        public string $name,
        public string $addressLine1,
        public ?string $addressLine2,
        public ?string $city,
        public ?string $postalCode,
        public ?string $timeZone,
        public ?string $branchId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the validated request payload
     */
    public static function fromValidated(string $restaurantId, array $input, ?string $branchId = null): self
    {
        $timeZone = $input['time_zone'] ?? null;

        return new self(
            restaurantId: $restaurantId,
            name: trim((string) $input['name']),
            addressLine1: trim((string) $input['address_line_1']),
            addressLine2: self::nullIfBlank($input['address_line_2'] ?? null),
            city: self::nullIfBlank($input['city'] ?? null),
            postalCode: self::nullIfBlank($input['postal_code'] ?? null),
            timeZone: $timeZone === null ? null : trim((string) $timeZone),
            branchId: $branchId,
        );
    }

    /**
     * The postal address the Zone module's resolver turns into a coordinate pair
     * (Requirement 32.14).
     */
    public function toPostalAddress(): PostalAddress
    {
        return new PostalAddress(
            $this->addressLine1,
            $this->addressLine2,
            $this->city,
            $this->postalCode,
        );
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
