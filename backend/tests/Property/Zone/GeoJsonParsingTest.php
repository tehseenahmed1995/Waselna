<?php

declare(strict_types=1);

// Feature: food-delivery-platform, Property 15: GeoJSON parsing accepts exactly the conforming grammar and reports the first violation

/*
 * *For any* GeoJSON text describing 1–10 linear rings of 4–1,000 closed
 * coordinate pairs within the latitude range -90 to 90 and longitude range
 * -180 to 180 and at most 1,000,000 characters, the GeoJSON_Parser produces a
 * geometry object structurally equal to the described polygon; and *for any*
 * such text with exactly one violation injected at a known zero-based character
 * offset, the parser rejects it with `invalid_geojson` reporting that offset and
 * leaves every stored Delivery_Zone unchanged.
 *
 * **Validates: Requirements 5.1, 5.2**
 *
 * The parser is framework-free `Domain/` code, so this property exercises it
 * directly with no database and no container. The conforming text is built from
 * a generated structural polygon through the canonical printer, which gives a
 * known-good geometry to compare the parse result against and a text whose every
 * character offset is known, so a violation can be injected at a chosen offset
 * and the reported offset asserted exactly.
 *
 * The generated text is ASCII throughout, so the parser's byte cursor and the
 * zero-based character offset of Requirement 5.2 coincide.
 */

use App\Modules\Zone\Domain\CanonicalGeoJsonPrinter;
use App\Modules\Zone\Domain\GeoJsonViolation;
use App\Modules\Zone\Domain\PolygonGeometry;
use App\Modules\Zone\Domain\RecursiveDescentGeoJsonParser;
use App\Shared\Domain\Coordinate;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;

uses(TestTrait::class);

/**
 * A decimal in the closed degree range [$min, $max] carrying 0 to 7 fractional
 * digits in canonical minimal form (no trailing fractional zeros, no negative
 * zero). The integer part is kept strictly inside the range so an appended
 * fractional part can never push the value past the bound, and the value
 * survives Coordinate normalisation and the printer unchanged, so the printed
 * text round-trips and the known offsets stay valid.
 */
function geoDecimal(int $min, int $max): ErisGenerator
{
    return Generators::map(
        static function (array $parts) use ($min, $max): string {
            [$integer, $fractionDigits] = $parts;

            if ($integer <= $min) {
                $integer = $min + 1;
            }

            if ($integer >= $max) {
                $integer = $max - 1;
            }

            $fraction = rtrim($fractionDigits, '0');

            $magnitude = $fraction === ''
                ? (string) abs($integer)
                : abs($integer).'.'.$fraction;

            if ($magnitude === '0') {
                return '0';
            }

            return $integer < 0 ? '-'.$magnitude : $magnitude;
        },
        Generators::tuple(
            Generators::choose($min, $max),
            Generators::map(
                static fn (int $value): string => str_pad((string) $value, 7, '0', STR_PAD_LEFT),
                Generators::choose(0, 9_999_999),
            ),
        ),
    );
}

function geoLongitude(): ErisGenerator
{
    return geoDecimal(-180, 180);
}

function geoLatitude(): ErisGenerator
{
    return geoDecimal(-90, 90);
}

/**
 * One generated conforming PolygonGeometry: 1 to 10 rings, each of 4 to 8
 * closed coordinate pairs. The pair count is kept to the low end of the 4..1000
 * range so each run stays fast while every structural bound (ring count,
 * closure, minimum pair count) is still exercised. Each ring's closing pair is
 * its first pair repeated, satisfying the closed-ring rule of Requirement 5.1.
 */
function geoPolygon(): ErisGenerator
{
    return Generators::bind(
        Generators::choose(PolygonGeometry::MIN_RINGS, PolygonGeometry::MAX_RINGS),
        static function (int $ringCount): ErisGenerator {
            $ringGenerators = [];

            for ($index = 0; $index < $ringCount; $index++) {
                $ringGenerators[] = geoRing();
            }

            return Generators::map(
                static fn (array $rings): PolygonGeometry => new PolygonGeometry($rings),
                Generators::tuple(...$ringGenerators),
            );
        },
    );
}

/**
 * A single closed linear ring of 4 to 8 coordinate pairs: n distinct generated
 * pairs followed by a repeat of the first, so the ring is closed and holds at
 * least the minimum of 4 pairs.
 *
 * @return ErisGenerator
 */
function geoRing(): ErisGenerator
{
    return Generators::bind(
        Generators::choose(PolygonGeometry::MIN_COORDINATES_PER_RING - 1, 7),
        static function (int $distinctCount): ErisGenerator {
            $pairGenerators = [];

            for ($index = 0; $index < $distinctCount; $index++) {
                $pairGenerators[] = geoPair();
            }

            return Generators::map(
                static function (array $pairs): array {
                    /** @var list<Coordinate> $pairs */
                    $pairs = array_values($pairs);
                    $pairs[] = $pairs[0];

                    return $pairs;
                },
                Generators::tuple(...$pairGenerators),
            );
        },
    );
}

/**
 * A single Coordinate from a generated longitude and latitude.
 */
function geoPair(): ErisGenerator
{
    return Generators::map(
        static fn (array $lonLat): Coordinate => new Coordinate($lonLat[1], $lonLat[0]),
        Generators::tuple(geoLongitude(), geoLatitude()),
    );
}

it('accepts exactly the conforming grammar and reports the first violation at its offset', function (): void {
    $parser = new RecursiveDescentGeoJsonParser;
    $printer = new CanonicalGeoJsonPrinter;

    $this->limitTo(100)
        ->forAll(geoPolygon())
        ->then(function (PolygonGeometry $geometry) use ($parser, $printer): void {
            // ---- Acceptance (Requirement 5.1) ---------------------------------
            // The canonical text of a conforming geometry parses back to a
            // geometry structurally equal to the original.
            $text = $printer->print($geometry);

            expect(strlen($text))->toBeLessThanOrEqual(RecursiveDescentGeoJsonParser::MAX_CHARACTERS);

            $parsed = $parser->parse($text);

            expect($parsed->equals($geometry))->toBeTrue()
                ->and($geometry->equals($parsed))->toBeTrue()
                ->and($parsed->ringCount())->toBe($geometry->ringCount());

            // ---- First violation (Requirement 5.2) ----------------------------
            // Each injection corrupts the conforming text at exactly one known
            // zero-based offset; the parser must reject with `invalid_geojson`
            // reporting that offset and must raise before producing a geometry.
            foreach (injectedViolations($text) as $case) {
                [$corrupt, $expectedOffset] = $case;

                try {
                    $parser->parse($corrupt);
                    throw new RuntimeException(sprintf(
                        'Expected a GeoJsonViolation at offset %d but parsing succeeded for: %s',
                        $expectedOffset,
                        $corrupt,
                    ));
                } catch (GeoJsonViolation $violation) {
                    expect($violation->errorCode())->toBe('invalid_geojson')
                        ->and($violation->offset())->toBe($expectedOffset);
                }
            }
        });
});

/**
 * A set of single-violation corruptions of a conforming canonical text, each
 * paired with the zero-based character offset at which the parser meets it.
 *
 * Every corruption changes the text at exactly one point and leaves the rest
 * conforming, so the parser's left-to-right scan meets this injected violation
 * first. Offsets are taken from the canonical text's fixed structure — the
 * leading "{", the "coordinates" array's opening "[", and the trailing "}" — so
 * each expected offset is derived from the text itself rather than hard-coded.
 *
 * @return list<array{0: string, 1: int}>
 */
function injectedViolations(string $text): array
{
    $length = strlen($text);
    $cases = [];

    // 1. The opening brace is replaced: the object must begin with "{", and the
    //    parser rejects at offset 0 after skipping leading whitespace.
    $cases[] = [substrReplaceAt($text, 'x', 0), 0];

    // 2. The "coordinates" array's opening "[" is replaced by a digit, so the
    //    parser rejects where it expects the rings array to open.
    $ringsOpen = strpos($text, '[');
    assert($ringsOpen !== false);
    $cases[] = [substrReplaceAt($text, '9', $ringsOpen), $ringsOpen];

    // 3. Trailing garbage after the closing "}": the object parses, then the
    //    scan meets an unexpected character at the former end of the text.
    $cases[] = [$text.'!', $length];

    // 4. The closing "}" is dropped: the object's final member is parsed, then
    //    the scan runs off the end where it expects "," or "}".
    $withoutBrace = substr($text, 0, $length - 1);
    $cases[] = [$withoutBrace, $length - 1];

    return $cases;
}

/**
 * Replaces exactly one byte of $text at $offset with $replacement.
 */
function substrReplaceAt(string $text, string $replacement, int $offset): string
{
    return substr($text, 0, $offset).$replacement.substr($text, $offset + 1);
}
