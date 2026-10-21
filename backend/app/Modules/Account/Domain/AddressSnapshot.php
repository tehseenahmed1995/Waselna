<?php

declare(strict_types=1);

namespace App\Modules\Account\Domain;

use App\Modules\Account\Boundary\AddressDirectory;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\PostalAddress;

/**
 * The decrypted, framework-free view of a Delivery_Address a cross-module
 * consumer receives through the {@see AddressDirectory}
 * boundary (design Account notes).
 *
 * The Order module copies these fields verbatim into the immutable address
 * snapshot it stores on an Order at checkout (Requirement 8.7, design
 * Property 28), and the Pricing module reads the {@see Coordinate} to compute the
 * distance-based Delivery_Fee (Requirement 7.2). Carrying a value object rather
 * than an Eloquent model keeps the Order and Pricing modules free of any direct
 * dependency on the Account module's tables (Requirement 22.8): the plaintext is
 * decrypted once, here, behind the owning module's boundary.
 */
final readonly class AddressSnapshot
{
    public function __construct(
        public string $addressId,
        public string $userId,
        public string $label,
        public string $line1,
        public ?string $line2,
        public string $locality,
        public string $postalCode,
        public string $countryCode,
        public Coordinate $coordinate,
    ) {}

    /**
     * The resolved coordinate, the single field Pricing reads for the
     * Delivery_Fee distance (Requirement 7.2).
     */
    public function coordinate(): Coordinate
    {
        return $this->coordinate;
    }

    /**
     * The address parts as a {@see PostalAddress}, so a consumer that needs to
     * re-resolve or display the address uses the same value object the Zone
     * module resolves.
     */
    public function toPostalAddress(): PostalAddress
    {
        return new PostalAddress($this->line1, $this->line2, $this->locality, $this->postalCode);
    }

    /**
     * The snapshot as the plain array the Order module stores as its immutable
     * `delivery_address_snapshot` jsonb (Requirement 8.7).
     *
     * @return array{
     *     address_id: string,
     *     label: string,
     *     line1: string,
     *     line2: ?string,
     *     locality: string,
     *     postal_code: string,
     *     country_code: string,
     *     latitude: string,
     *     longitude: string
     * }
     */
    public function toArray(): array
    {
        return [
            'address_id' => $this->addressId,
            'label' => $this->label,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'locality' => $this->locality,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
            'latitude' => $this->coordinate->latitude,
            'longitude' => $this->coordinate->longitude,
        ];
    }
}
