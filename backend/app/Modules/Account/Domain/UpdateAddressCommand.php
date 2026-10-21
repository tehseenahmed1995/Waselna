<?php

declare(strict_types=1);

namespace App\Modules\Account\Domain;

use App\Shared\Domain\PostalAddress;

/**
 * A Customer's request to update a Delivery_Address (Requirement 31.7, 31.11).
 *
 * An update carries the full set of address fields: a changed address line,
 * locality, or postal code re-resolves the coordinate through the Zone module,
 * so the command exposes the parts as a {@see PostalAddress} exactly as the
 * create command does. The targeted address identifier is validated against the
 * requesting Customer's non-archived addresses by the service, which returns the
 * non-disclosing `address_not_found` where it does not match (Requirement 31.11).
 */
final readonly class UpdateAddressCommand
{
    public string $countryCode;

    public function __construct(
        public string $userId,
        public string $addressId,
        public string $label,
        public string $line1,
        public ?string $line2,
        public string $locality,
        public string $postalCode,
        string $countryCode,
    ) {
        $this->countryCode = mb_strtoupper(trim($countryCode));
    }

    /**
     * @param  array<string, mixed>  $input  the validated request payload
     */
    public static function fromValidated(string $userId, string $addressId, array $input): self
    {
        $line2 = array_key_exists('line2', $input) && $input['line2'] !== null
            ? trim((string) $input['line2'])
            : null;

        return new self(
            userId: $userId,
            addressId: $addressId,
            label: trim((string) $input['label']),
            line1: trim((string) $input['line1']),
            line2: $line2 === '' ? null : $line2,
            locality: trim((string) $input['locality']),
            postalCode: trim((string) $input['postal_code']),
            countryCode: (string) $input['country_code'],
        );
    }

    /**
     * The address parts as the value object the Zone AddressResolver resolves
     * (Requirement 5.6).
     */
    public function toPostalAddress(): PostalAddress
    {
        return new PostalAddress($this->line1, $this->line2, $this->locality, $this->postalCode);
    }
}
