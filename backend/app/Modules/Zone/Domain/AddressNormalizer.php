<?php

declare(strict_types=1);

namespace App\Modules\Zone\Domain;

use App\Modules\Zone\Models\GeocodeCache;
use App\Shared\Domain\PostalAddress;

/**
 * Derives the stable, normalized key a {@see GeocodeCache}
 * row is stored under (design Zone notes: "a `geocode_cache` row keyed by
 * normalized address avoids repeat work").
 *
 * Normalisation folds away the differences that should not produce two cache
 * rows — surrounding whitespace, internal whitespace runs, and letter case — so
 * the same place submitted twice resolves once and the local deterministic
 * adapter returns the same coordinate for either spelling. It is framework-free:
 * the same key is derivable from a Form_Request, a Module_Boundary caller, or a
 * queued Job.
 */
final class AddressNormalizer
{
    private function __construct() {}

    /**
     * The normalized key: the address parts joined with a single separator, each
     * lower-cased and collapsed on internal whitespace. Blank optional parts are
     * already dropped by {@see PostalAddress::parts()}.
     */
    public static function key(PostalAddress $address): string
    {
        $normalisedParts = array_map(
            static fn (string $part): string => self::foldPart($part),
            $address->parts(),
        );

        return implode(', ', $normalisedParts);
    }

    /**
     * The SHA-256 hex digest of the normalized key, which is what the unique
     * `address_hash` column stores. A fixed-width hash keeps the cache lookup
     * indexed regardless of how long the submitted address is.
     */
    public static function hash(PostalAddress $address): string
    {
        return hash('sha256', self::key($address));
    }

    private static function foldPart(string $part): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($part)) ?? '';

        return mb_strtolower($collapsed);
    }
}
