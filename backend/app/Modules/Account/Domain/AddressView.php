<?php

declare(strict_types=1);

namespace App\Modules\Account\Domain;

use App\Modules\Account\Models\DeliveryAddress;

/**
 * The Customer-facing projection of a Delivery_Address (Requirements 31.7,
 * 31.11).
 *
 * It returns the decrypted address parts to their owner together with the
 * resolved coordinate and the default flag; it carries no `archived_at`, since
 * an archived address is never projected into a list the Customer reads
 * (Requirement 31.10, 31.11).
 */
final readonly class AddressView
{
    public function __construct(
        public string $id,
        public string $label,
        public string $line1,
        public ?string $line2,
        public string $locality,
        public string $postalCode,
        public string $countryCode,
        public string $latitude,
        public string $longitude,
        public bool $isDefault,
    ) {}

    /**
     * Projects a decrypted model row. The `*_encrypted` attributes read back as
     * plaintext through the model's `encrypted` casts (Requirement 28.6).
     */
    public static function fromModel(DeliveryAddress $address): self
    {
        return new self(
            id: $address->id,
            label: $address->label,
            line1: (string) $address->line1_encrypted,
            line2: $address->line2_encrypted,
            locality: (string) $address->locality_encrypted,
            postalCode: (string) $address->postal_code_encrypted,
            countryCode: $address->country_code,
            latitude: (string) $address->latitude,
            longitude: (string) $address->longitude,
            isDefault: $address->is_default,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     line1: string,
     *     line2: ?string,
     *     locality: string,
     *     postal_code: string,
     *     country_code: string,
     *     latitude: string,
     *     longitude: string,
     *     is_default: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'locality' => $this->locality,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_default' => $this->isDefault,
        ];
    }
}
