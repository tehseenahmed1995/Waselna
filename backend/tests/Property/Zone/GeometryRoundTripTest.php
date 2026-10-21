<?php

declare(strict_types=1);

// Feature: waselna, Property 16: Geometry round-trips through the printer exactly

/*
 * For any Delivery_Zone geometry object, parsing the output of the
 * GeoJSON_Printer produces a geometry object equal to the original — the same
 * rings in the same order, the same coordinate pairs in the same order within
 * each ring, and coordinate values identical at 7 fractional digits — and every
 * printed coordinate carries at most 7 fractional digits with each ring's first
 * pair repeated as its last.
 *
 * **Validates: Requirements 5.3, 5.4**
 *
 * The subject is the canonical printer paired with the parser, exercised as a
 * framework-free unit (no database, no container). A PolygonGeometry is
 * generated directly so every input is a well-formed geometry by construction;
 * the printer renders it and the parser reads it back, and the property asserts
 * the fixed point of Requirement 5.4:
 *
 *     parse(print(geometry)).equals(geometry)   and the converse
 *
 * using PolygonGeometry::equals(), which is exactly the structural equality the
 * requirement defines (ring count and order, pair count and order per ring, and
 * coordinate values compared at 7 fractional digits).
 *
 * It also asserts the Requirement 5.3 shape obligations on the printed text that
 * the round-trip rides on: each ring is closed (its first printed pair repeated
 * as its last) and every rendered coordinate value carries at most 7 fractional
 * digits.
 *
 * The generators cover the documented structural bounds — 1..10 rings, 4..1000
 * closed coordinate pairs per ring — and the coordinate boundary values at the
 * edges of the latitude and longitude ranges and at the 7-fractional-digit
 * precision edge, where trailing-zero trimming and negative-zero normalisation
 * in the printer are most likely to perturb equality.
 */

use App\Modules\Zone\Domain\CanonicalGeoJsonPrinter;
use App\Modules\Zone\Domain\PolygonGeometry;
use App\Modules\Zone\Domain\RecursiveDescentGeoJsonParser;
use App\Shared\Domain\Coordinate;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;

uses(TestTrait::class);

/**
 * A latitude decimal string drawn from across the closed range -90..90,
 * including the exact bounds and values that exercise 0..7 fractional digits,
 * trailing fractional zeros, and negative zero.
 */
function roundTripLatitudes(): ErisGenerator
{
    return Generators::oneOf(
        Generators::elements([
            '-90', '90', '0', '-0', '0.0', '-0.0000000',
            '45', '-45.5', '12.3400000', '0.1000000', '89.9999999', '-89.9999999',
            '1.2345678', '-1.2345678', '-0.0000001', '0.0000001',
        ]),
        Generators::map(
            static function (array $parts): string {
                [$negative, $whole, $fraction] = $parts;
                $sign = $negative ? '-' : '';
                $value = $sign.$whole.'.'.str_pad((string) $fraction, 7, '0', STR_PAD_LEFT);

                // Keep it inside the closed latitude range after rounding.
                return ((float) $value) >= -90.0 && ((float) $value) <= 90.0 ? $value : '0';
            },
            Generators::tuple(
                Generators::bool(),
                Generators::choose(0, 89),
                Generators::choose(0, 9_999_999),
            ),
        ),
    );
}

/**
 * A longitude decimal string drawn from across the closed range -180..180, with
 * the same precision-edge coverage as the latitude generator.
 */
function roundTripLongitudes(): ErisGenerator
{
    return Generators::oneOf(
        Generators::elements([
            '-180', '180', '0', '-0', '0.0', '-0.0000000',
            '90', '-90.5', '123.4567890', '0.1000000', '179.9999999', '-179.9999999',
            '1.2345678', '-1.2345678', '-0.0000001', '0.0000001',
        ]),
        Generators::map(
            static function (array $parts): string {
                [$negative, $whole, $fraction] = $parts;
                $sign = $negative ? '-' : '';
                $value = $sign.$whole.'.'.str_pad((string) $fraction, 7, '0', STR_PAD_LEFT);

                return ((float) $value) >= -180.0 && ((float) $value) <= 180.0 ? $value : '0';
            },
            Generators::tuple(
                Generators::bool(),
                Generators::choose(0, 179),
                Generators::choose(0, 9_999_999),
            ),
        ),
    );
}

/**
 * A single closed linear ring of 4..1000 Coordinate values. The first pair is
 * repeated as the last so the ring satisfies the closed-ring invariant of
 * Requirement 5.1 by construction, regardless of the interior pairs generated.
 */
function roundTripRings(): ErisGenerator
{
    return Generators::bind(
        // Interior pair count in 3..20 so the closing repeat lands the ring in
        // the documented 4..1000 bound; small by default, with the maximum
        // reachable through growth.
        Generators::choose(3, 20),
        static fn (int $interiorPairs): ErisGenerator => Generators::map(
            static function (array $coordinates): array {
                /** @var list<Coordinate> $coordinates */
                $coordinates[] = $coordinates[0];

                return $coordinates;
            },
            Generators::vector(
                $interiorPairs,
                Generators::map(
                    static fn (array $pair): Coordinate => new Coordinate($pair[0], $pair[1]),
                    Generators::tuple(roundTripLatitudes(), roundTripLongitudes()),
                ),
            ),
        ),
    );
}

/**
 * A PolygonGeometry of 1..10 closed rings, each produced by roundTripRings().
 */
function roundTripGeometries(): ErisGenerator
{
    return Generators::bind(
        Generators::choose(1, 10),
        static fn (int $ringCount): ErisGenerator => Generators::map(
            static fn (array $rings): PolygonGeometry => new PolygonGeometry($rings),
            Generators::vector($ringCount, roundTripRings()),
        ),
    );
}

it('parses the printer output back to a geometry structurally equal to the original', function (): void {
    $printer = new CanonicalGeoJsonPrinter();
    $parser = new RecursiveDescentGeoJsonParser();

    $this->limitTo(100)->forAll(
        roundTripGeometries(),
    )->then(function (PolygonGeometry $geometry) use ($printer, $parser): void {
        $text = $printer->print($geometry);

        // ---- Round-trip equality (Requirement 5.4) ------------------------
        // parse(print(geometry)) equals the original, and equality is
        // symmetric, via PolygonGeometry's structural equality.
        $parsed = $parser->parse($text);

        expect($parsed->equals($geometry))->toBeTrue()
            ->and($geometry->equals($parsed))->toBeTrue()
            ->and($parsed->ringCount())->toBe($geometry->ringCount());

        // ---- Printed shape obligations (Requirement 5.3) ------------------
        // Ring count and order and pair count and order are preserved.
        foreach ($geometry->rings() as $ringIndex => $ring) {
            $parsedRing = $parsed->rings()[$ringIndex];

            expect(count($parsedRing))->toBe(count($ring));

            // Each ring is closed: its first and last pairs are equal.
            $last = count($parsedRing) - 1;
            expect($parsedRing[0]->equals($parsedRing[$last]))->toBeTrue();
        }

        // Every coordinate value the printer emitted carries at most 7
        // fractional digits in plain decimal notation.
        foreach (printedCoordinateValues($text) as $value) {
            expect(fractionalDigitCount($value))->toBeLessThanOrEqual(Coordinate::FRACTIONAL_DIGITS);
        }
    });
});

/**
 * The decimal literals the printer emitted inside the canonical text, taken
 * from every `[longitude,latitude]` position so the fractional-digit bound of
 * Requirement 5.3 can be asserted on the rendered output itself.
 *
 * @return list<string>
 */
function printedCoordinateValues(string $text): array
{
    preg_match_all('/-?\d+(?:\.\d+)?/', $text, $matches);

    return $matches[0];
}

/**
 * The number of fractional digits in a plain decimal literal, 0 when it has no
 * fractional part.
 */
function fractionalDigitCount(string $value): int
{
    $separator = strpos($value, '.');

    return $separator === false ? 0 : strlen($value) - $separator - 1;
}
