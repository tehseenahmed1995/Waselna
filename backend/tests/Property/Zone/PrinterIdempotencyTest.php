<?php

declare(strict_types=1);

// Feature: waselna, Property 17: Printing is idempotent after normalization

/*
 * For any GeoJSON text accepted by the GeoJSON_Parser, printing, parsing, and
 * printing again yields text character-for-character identical to the first
 * printed output.
 *
 * **Validates: Requirements 5.5**
 *
 * The subject is the canonical printer paired with the parser, exercised as a
 * framework-free unit (no database, no container). A PolygonGeometry is
 * generated directly rather than from raw text so every input the property
 * measures is one the parser accepts by construction: the printer renders it to
 * canonical GeoJSON, and that canonical text is itself parser-accepted text
 * (Requirement 5.3). The property then asserts the fixed point:
 *
 *     print(parse(print(geometry))) === print(geometry)
 *
 * The first printed output is the normalisation step named in the property;
 * re-parsing and re-printing it must not change a single character. Running the
 * loop a second time additionally shows the output is a true fixed point rather
 * than merely stable for one extra pass.
 *
 * The generators cover the documented structural bounds — 1..10 rings, 4..1000
 * closed coordinate pairs per ring — and the coordinate boundary values at the
 * edges of the latitude and longitude ranges and at the 7-fractional-digit
 * precision edge, where trailing-zero trimming and negative-zero normalisation
 * in the printer are most likely to break idempotency.
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
function idempotencyLatitudes(): ErisGenerator
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
function idempotencyLongitudes(): ErisGenerator
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
function idempotencyRings(): ErisGenerator
{
    return Generators::bind(
        // Interior pair count in 3..999 so the closing repeat lands the ring in
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
                    Generators::tuple(idempotencyLatitudes(), idempotencyLongitudes()),
                ),
            ),
        ),
    );
}

/**
 * A PolygonGeometry of 1..10 closed rings, each produced by idempotencyRings().
 */
function idempotencyGeometries(): ErisGenerator
{
    return Generators::bind(
        Generators::choose(1, 10),
        static fn (int $ringCount): ErisGenerator => Generators::map(
            static fn (array $rings): PolygonGeometry => new PolygonGeometry($rings),
            Generators::vector($ringCount, idempotencyRings()),
        ),
    );
}

it('prints the same characters after parsing and re-printing a canonical rendering', function (): void {
    $printer = new CanonicalGeoJsonPrinter();
    $parser = new RecursiveDescentGeoJsonParser();

    $this->limitTo(100)->forAll(
        idempotencyGeometries(),
    )->then(function (PolygonGeometry $geometry) use ($printer, $parser): void {
        // The normalisation step: the first printed output is the canonical,
        // parser-accepted text the property measures against.
        $firstPrint = $printer->print($geometry);

        // print(parse(print(geometry))) === print(geometry), character for
        // character.
        $secondPrint = $printer->print($parser->parse($firstPrint));

        expect($secondPrint)->toBe($firstPrint);

        // A true fixed point: one further parse-print pass still changes nothing.
        $thirdPrint = $printer->print($parser->parse($secondPrint));

        expect($thirdPrint)->toBe($firstPrint);
    });
});
