<?php

declare(strict_types=1);

namespace App\Modules\Account\Domain;

use App\Shared\Domain\PostalAddress;

/**
 * A Customer's request to create a Delivery_Address (Requirement 31.7).
 *
 * The owning Customer comes from the authenticated actor, never the body. The
 * free-form parts are also exposed as a {@see PostalAddress} so the service can
 * hand them to the Zone module's AddressResolver without rebuilding the value
 * object. The country code is normalised to upper case at construction so the
 * stored ISO 3166-1 alpha-2 value is canonical.
 */
final readonly class CreateAddressCommand
{
    public string $countryCode;

    public function __construct(
        public string $userId,
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
    public static function fromValidated(string $userId, array $input): self
    {
        $line2 = array_key_exists('line2', $input) && $input['line2'] !== null
            ? trim((string) $input['line2'])
            : null;

        return new self(
            userId: $userId,
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
