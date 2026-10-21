<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Modules\Zone\Contracts\Services\AddressResolver;
use InvalidArgumentException;

/**
 * A postal address submitted for resolution to a coordinate pair.
 *
 * This is the input the Zone module's {@see AddressResolver}
 * resolves (Requirements 5.6, 5.7), carrying the same free-form fields the
 * Account module stores against a Delivery_Address (`address_line_1`,
 * `address_line_2`, `city`, `postal_code`) and the Catalog module submits with a
 * Restaurant_Branch (Requirement 32.14). It is a framework-free value object so
 * it can be constructed from a Form_Request, passed across a Module_Boundary,
 * and used from a queued Job without touching the container.
 *
 * The resolver keys its `geocode_cache` row on the normalized form, so the
 * address is held here only as its raw parts and normalisation lives in the Zone
 * module next to the cache that depends on it.
 */
final readonly class PostalAddress
{
    public string $line1;

    public ?string $line2;

    public ?string $city;

    public ?string $postalCode;

    public function __construct(string $line1, ?string $line2 = null, ?string $city = null, ?string $postalCode = null)
    {
        $line1 = trim($line1);

        if ($line1 === '') {
            throw new InvalidArgumentException('A postal address requires a non-empty first address line.');
        }

        $this->line1 = $line1;
        $this->line2 = self::nullIfBlank($line2);
        $this->city = self::nullIfBlank($city);
        $this->postalCode = self::nullIfBlank($postalCode);
    }

    public static function of(string $line1, ?string $line2 = null, ?string $city = null, ?string $postalCode = null): self
    {
        return new self($line1, $line2, $city, $postalCode);
    }

    /**
     * The address parts in a stable order, used by the Zone module to derive the
     * normalized cache key. Blank optional parts are omitted so two addresses
     * that differ only by an empty line share one cache row.
     *
     * @return list<string>
     */
    public function parts(): array
    {
        return array_values(array_filter(
            [$this->line1, $this->line2, $this->city, $this->postalCode],
            static fn (?string $part): bool => $part !== null && $part !== '',
        ));
    }

    private static function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
