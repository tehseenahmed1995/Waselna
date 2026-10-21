<?php

declare(strict_types=1);

namespace App\Modules\Zone\Domain;

use App\Shared\Domain\Exceptions\DomainException;

/**
 * The first grammar or bound violation the GeoJSON_Parser meets in a submitted
 * document (Requirement 5.2).
 *
 * The parser scans left to right and raises this the instant it meets a
 * violation, carrying the zero-based character offset of that first violation.
 * It extends the base DomainException with the `invalid_geojson` code — which
 * the error catalogue records as HTTP 422 — so the single exception handler
 * renders it without a Zone-specific branch, and the offset travels in the
 * context so it reaches the body as the design's error catalogue requires while
 * leaving every stored Delivery_Zone unchanged. It extends DomainException
 * rather than the final ValidationException so it can carry the offset as a
 * first-class property.
 *
 * Framework-free: it touches no request, response, or container, so the
 * framework-free parser in `Domain/` can raise it directly.
 */
final class GeoJsonViolation extends DomainException
{
    public function __construct(
        public readonly int $offset,
        string $reason,
    ) {
        parent::__construct(
            'invalid_geojson',
            $reason,
            [],
            ['offset' => $offset],
        );
    }

    /**
     * The zero-based character offset of the first violation (Requirement 5.2).
     */
    public function offset(): int
    {
        return $this->offset;
    }
}
