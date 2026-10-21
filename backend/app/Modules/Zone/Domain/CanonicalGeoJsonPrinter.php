<?php

declare(strict_types=1);

namespace App\Modules\Zone\Domain;

use App\Modules\Zone\Contracts\Services\GeoJsonPrinter;
use App\Shared\Domain\Coordinate;

/**
 * A framework-free printer that renders a PolygonGeometry as canonical GeoJSON
 * Polygon text (Requirements 5.3, 5.4, 5.5).
 *
 * The output is deterministic and canonical so the round-trip properties hold
 * exactly:
 *
 *  - member order is fixed ("type" then "coordinates"), with no insignificant
 *    whitespace, so re-printing a parsed geometry is character-for-character
 *    identical (Requirement 5.5);
 *  - ring order and coordinate order are preserved, and each ring's first pair
 *    is repeated as its last (Requirement 5.3) — the PolygonGeometry already
 *    carries that closing pair, so printing simply emits every pair in order;
 *  - each coordinate value is rendered in plain decimal notation with at most 7
 *    fractional digits, trailing fractional zeros removed and no bare trailing
 *    separator, which is "at most 7 fractional digits" of Requirement 5.3 and
 *    the equality-at-7-digits of Requirement 5.4.
 *
 * Coordinate holds its latitude and longitude already normalised to 7
 * fractional digits, so this printer never rounds; it only trims to the
 * canonical minimal form.
 */
final class CanonicalGeoJsonPrinter implements GeoJsonPrinter
{
    public function print(PolygonGeometry $geometry): string
    {
        $rings = [];

        foreach ($geometry->rings() as $ring) {
            $positions = [];

            foreach ($ring as $coordinate) {
                $positions[] = sprintf(
                    '[%s,%s]',
                    self::canonicalNumber($coordinate->longitude),
                    self::canonicalNumber($coordinate->latitude),
                );
            }

            $rings[] = '['.implode(',', $positions).']';
        }

        return '{"type":"Polygon","coordinates":['.implode(',', $rings).']}';
    }

    /**
     * The minimal decimal form of a value already normalised to 7 fractional
     * digits: trailing fractional zeros removed, the decimal separator dropped
     * when the fractional part vanishes, and negative zero rendered as "0".
     */
    private static function canonicalNumber(string $value): string
    {
        $negative = $value[0] === '-';
        $magnitude = $negative ? substr($value, 1) : $value;

        $separator = strpos($magnitude, '.');

        if ($separator !== false) {
            $magnitude = rtrim($magnitude, '0');
            $magnitude = rtrim($magnitude, '.');
        }

        if ($magnitude === '') {
            $magnitude = '0';
        }

        if ($magnitude === '0') {
            return '0';
        }

        return $negative ? '-'.$magnitude : $magnitude;
    }

    /**
     * Kept for callers wanting the GeoJSON longitude-first position tuple of a
     * single Coordinate without printing a whole geometry.
     *
     * @return array{0: string, 1: string}
     */
    public function position(Coordinate $coordinate): array
    {
        return [
            self::canonicalNumber($coordinate->longitude),
            self::canonicalNumber($coordinate->latitude),
        ];
    }
}
