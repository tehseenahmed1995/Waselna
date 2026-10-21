<?php

declare(strict_types=1);

namespace App\Modules\Zone\Domain;

use App\Modules\Zone\Contracts\Services\GeoJsonParser;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\Decimal;

/**
 * A framework-free, offset-tracking recursive-descent parser for the GeoJSON
 * Polygon grammar (Requirements 5.1, 5.2).
 *
 * The parser deliberately does not delegate to json_decode. json_decode cannot
 * report the zero-based character offset of the first violation into the
 * original text, cannot distinguish a grammar violation from a Polygon bound
 * violation, and reorders object members — all three of which Requirements 5.1
 * and 5.2 require. Instead this scans the text left to right over a single
 * cursor, and the instant it meets a violation it raises a GeoJsonViolation
 * carrying the cursor position, which is the zero-based offset of that first
 * violation.
 *
 * The accepted grammar is the GeoJSON Polygon object narrowed to the
 * Delivery_Zone limits:
 *
 *   document   = ws object ws
 *   object     = '{' ws member (ws ',' ws member)* ws '}'
 *   member     = string ws ':' ws value          ; "type" and "coordinates" required
 *   type        → the string "Polygon"
 *   coordinates → '[' ring (',' ring)* ']'        ; 1..10 rings
 *   ring        → '[' position (',' position)* ']'; 4..1000 closed positions
 *   position    → '[' number ',' number ']'       ; longitude, latitude
 *
 * Numbers are JSON numbers without exponent notation (a stored coordinate is
 * never written that way); each is range-checked and normalised through
 * Coordinate, so a produced PolygonGeometry can never hold an out-of-range or
 * float-imprecise value.
 */
final class RecursiveDescentGeoJsonParser implements GeoJsonParser
{
    public const MAX_CHARACTERS = 1_000_000;

    private string $text;

    private int $length;

    private int $cursor;

    public function parse(string $text): PolygonGeometry
    {
        if (mb_strlen($text, 'UTF-8') > self::MAX_CHARACTERS) {
            // The first character past the bound is the first violation.
            $offset = $this->byteOffsetOfCharacter($text, self::MAX_CHARACTERS);
            throw new GeoJsonViolation(
                $offset,
                sprintf('GeoJSON text exceeds the %d-character bound.', self::MAX_CHARACTERS),
            );
        }

        $this->text = $text;
        $this->length = strlen($text);
        $this->cursor = 0;

        $this->skipWhitespace();
        [$type, $coordinates] = $this->parsePolygonObject();
        $this->skipWhitespace();

        if ($this->cursor !== $this->length) {
            throw $this->violation('Unexpected trailing characters after the GeoJSON object.');
        }

        if ($type !== 'Polygon') {
            // The offset is recorded at the point the type value was read.
            throw new GeoJsonViolation(
                $coordinates['typeOffset'],
                sprintf('A Delivery_Zone geometry must have type "Polygon"; received "%s".', $type),
            );
        }

        return new PolygonGeometry($coordinates['rings']);
    }

    /**
     * Parses `{ "type": ..., "coordinates": ... }` in any member order,
     * requiring exactly the two members and rejecting duplicates and strangers.
     *
     * @return array{0: string, 1: array{rings: list<list<Coordinate>>, typeOffset: int}}
     */
    private function parsePolygonObject(): array
    {
        $this->expect('{', 'A GeoJSON geometry must be a JSON object beginning with "{".');

        $type = null;
        $typeOffset = 0;
        $rings = null;

        $this->skipWhitespace();

        if ($this->peek() === '}') {
            throw $this->violation('A Delivery_Zone geometry requires "type" and "coordinates" members.');
        }

        while (true) {
            $this->skipWhitespace();
            $key = $this->parseString();
            $this->skipWhitespace();
            $this->expect(':', 'A JSON object member requires ":" between its name and value.');
            $this->skipWhitespace();

            if ($key === 'type') {
                if ($type !== null) {
                    throw $this->violation('The "type" member appears more than once.');
                }
                $typeOffset = $this->cursor;
                $type = $this->parseString();
            } elseif ($key === 'coordinates') {
                if ($rings !== null) {
                    throw $this->violation('The "coordinates" member appears more than once.');
                }
                $rings = $this->parseRings();
            } else {
                throw $this->violation(sprintf('Unexpected member "%s" in a Delivery_Zone geometry.', $key));
            }

            $this->skipWhitespace();
            $next = $this->peek();

            if ($next === ',') {
                $this->cursor++;

                continue;
            }

            if ($next === '}') {
                $this->cursor++;

                break;
            }

            throw $this->violation('A JSON object member must be followed by "," or "}".');
        }

        if ($type === null) {
            throw $this->violation('A Delivery_Zone geometry requires a "type" member.');
        }

        if ($rings === null) {
            throw $this->violation('A Delivery_Zone geometry requires a "coordinates" member.');
        }

        return [$type, ['rings' => $rings, 'typeOffset' => $typeOffset]];
    }

    /**
     * Parses `[ ring (, ring)* ]` and enforces the 1..10 ring bound.
     *
     * @return list<list<Coordinate>>
     */
    private function parseRings(): array
    {
        $this->expect('[', 'A Polygon\'s "coordinates" must be an array of linear rings.');
        $this->skipWhitespace();

        $rings = [];

        if ($this->peek() === ']') {
            // Zero rings — fewer than the minimum of 1.
            throw $this->violation(sprintf(
                'A Polygon holds between %d and %d linear rings; found 0.',
                PolygonGeometry::MIN_RINGS,
                PolygonGeometry::MAX_RINGS,
            ));
        }

        while (true) {
            if (count($rings) === PolygonGeometry::MAX_RINGS) {
                // The next ring would be the eleventh; its "[" is the violation.
                throw $this->violation(sprintf(
                    'A Polygon holds at most %d linear rings.',
                    PolygonGeometry::MAX_RINGS,
                ));
            }

            $rings[] = $this->parseRing();
            $this->skipWhitespace();
            $next = $this->peek();

            if ($next === ',') {
                $this->cursor++;
                $this->skipWhitespace();

                continue;
            }

            if ($next === ']') {
                $this->cursor++;

                break;
            }

            throw $this->violation('A linear ring must be followed by "," or "]".');
        }

        return $rings;
    }

    /**
     * Parses `[ position (, position)* ]` and enforces the 4..1000 pair bound
     * and the closed-ring rule.
     *
     * @return list<Coordinate>
     */
    private function parseRing(): array
    {
        $ringStart = $this->cursor;
        $this->expect('[', 'A linear ring must be an array of coordinate positions.');
        $this->skipWhitespace();

        $positions = [];

        if ($this->peek() === ']') {
            throw $this->violation(sprintf(
                'A linear ring holds between %d and %d coordinate pairs; found 0.',
                PolygonGeometry::MIN_COORDINATES_PER_RING,
                PolygonGeometry::MAX_COORDINATES_PER_RING,
            ));
        }

        while (true) {
            if (count($positions) === PolygonGeometry::MAX_COORDINATES_PER_RING) {
                throw $this->violation(sprintf(
                    'A linear ring holds at most %d coordinate pairs.',
                    PolygonGeometry::MAX_COORDINATES_PER_RING,
                ));
            }

            $positions[] = $this->parsePosition();
            $this->skipWhitespace();
            $next = $this->peek();

            if ($next === ',') {
                $this->cursor++;
                $this->skipWhitespace();

                continue;
            }

            if ($next === ']') {
                $this->cursor++;

                break;
            }

            throw $this->violation('A coordinate position must be followed by "," or "]".');
        }

        $pairCount = count($positions);

        if ($pairCount < PolygonGeometry::MIN_COORDINATES_PER_RING) {
            throw new GeoJsonViolation($ringStart, sprintf(
                'A linear ring holds between %d and %d coordinate pairs; found %d.',
                PolygonGeometry::MIN_COORDINATES_PER_RING,
                PolygonGeometry::MAX_COORDINATES_PER_RING,
                $pairCount,
            ));
        }

        if (! $positions[0]->equals($positions[$pairCount - 1])) {
            throw new GeoJsonViolation(
                $ringStart,
                'A linear ring must be closed; its first and last coordinate pairs differ.',
            );
        }

        return $positions;
    }

    /**
     * Parses `[ longitude , latitude ]`. GeoJSON orders longitude first.
     */
    private function parsePosition(): Coordinate
    {
        $this->expect('[', 'A coordinate position must be a two-element array.');
        $this->skipWhitespace();
        $longitudeOffset = $this->cursor;
        $longitude = $this->parseNumber();
        $this->skipWhitespace();
        $this->expect(',', 'A coordinate position must hold a longitude and a latitude separated by ",".');
        $this->skipWhitespace();
        $latitudeOffset = $this->cursor;
        $latitude = $this->parseNumber();
        $this->skipWhitespace();
        $this->expect(']', 'A coordinate position must hold exactly two numbers.');

        if (Decimal::compare($longitude, Coordinate::MIN_LONGITUDE, Coordinate::FRACTIONAL_DIGITS) < 0
            || Decimal::compare($longitude, Coordinate::MAX_LONGITUDE, Coordinate::FRACTIONAL_DIGITS) > 0) {
            throw new GeoJsonViolation($longitudeOffset, sprintf(
                'A longitude must fall in the closed range %s to %s; received "%s".',
                Coordinate::MIN_LONGITUDE,
                Coordinate::MAX_LONGITUDE,
                $longitude,
            ));
        }

        if (Decimal::compare($latitude, Coordinate::MIN_LATITUDE, Coordinate::FRACTIONAL_DIGITS) < 0
            || Decimal::compare($latitude, Coordinate::MAX_LATITUDE, Coordinate::FRACTIONAL_DIGITS) > 0) {
            throw new GeoJsonViolation($latitudeOffset, sprintf(
                'A latitude must fall in the closed range %s to %s; received "%s".',
                Coordinate::MIN_LATITUDE,
                Coordinate::MAX_LATITUDE,
                $latitude,
            ));
        }

        return new Coordinate($latitude, $longitude);
    }

    /**
     * Reads a JSON string literal and returns its unescaped value.
     */
    private function parseString(): string
    {
        if ($this->peek() !== '"') {
            throw $this->violation('A JSON string must begin with \'"\'.');
        }

        $this->cursor++;
        $value = '';

        while ($this->cursor < $this->length) {
            $character = $this->text[$this->cursor];

            if ($character === '"') {
                $this->cursor++;

                return $value;
            }

            if ($character === '\\') {
                $this->cursor++;

                if ($this->cursor >= $this->length) {
                    break;
                }

                $escape = $this->text[$this->cursor];
                $value .= match ($escape) {
                    '"' => '"',
                    '\\' => '\\',
                    '/' => '/',
                    'b' => "\x08",
                    'f' => "\x0C",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'u' => $this->parseUnicodeEscape(),
                    default => throw new GeoJsonViolation(
                        $this->cursor - 1,
                        sprintf('Unsupported escape sequence "\\%s" in a JSON string.', $escape),
                    ),
                };
                $this->cursor++;

                continue;
            }

            $value .= $character;
            $this->cursor++;
        }

        throw $this->violation('A JSON string is missing its closing \'"\'.');
    }

    /**
     * Reads the four hex digits of a `\u` escape and returns their UTF-8 byte
     * sequence. The cursor is left on the last hex digit so the caller's
     * trailing increment lands past it.
     */
    private function parseUnicodeEscape(): string
    {
        $hex = substr($this->text, $this->cursor + 1, 4);

        if (strlen($hex) !== 4 || preg_match('/^[0-9A-Fa-f]{4}$/', $hex) !== 1) {
            throw new GeoJsonViolation(
                $this->cursor - 1,
                'A "\\u" escape must be followed by exactly four hexadecimal digits.',
            );
        }

        $this->cursor += 4;

        return mb_chr((int) hexdec($hex), 'UTF-8');
    }

    /**
     * Reads a JSON number without exponent notation and returns it verbatim as
     * a decimal string for Decimal/Coordinate to range-check and normalise.
     */
    private function parseNumber(): string
    {
        $start = $this->cursor;

        if ($this->peek() === '-') {
            $this->cursor++;
        }

        $integerStart = $this->cursor;

        while ($this->cursor < $this->length && ctype_digit($this->text[$this->cursor])) {
            $this->cursor++;
        }

        if ($this->cursor === $integerStart) {
            throw new GeoJsonViolation($start, 'A JSON number requires at least one integer digit.');
        }

        if ($this->peek() === '.') {
            $this->cursor++;
            $fractionStart = $this->cursor;

            while ($this->cursor < $this->length && ctype_digit($this->text[$this->cursor])) {
                $this->cursor++;
            }

            if ($this->cursor === $fractionStart) {
                throw $this->violation('A JSON number\'s fractional part requires at least one digit.');
            }
        }

        if ($this->peek() === 'e' || $this->peek() === 'E') {
            throw $this->violation('Exponent notation is not accepted in a Delivery_Zone coordinate.');
        }

        return substr($this->text, $start, $this->cursor - $start);
    }

    private function expect(string $character, string $reason): void
    {
        if ($this->peek() !== $character) {
            throw $this->violation($reason);
        }

        $this->cursor++;
    }

    private function peek(): ?string
    {
        return $this->cursor < $this->length ? $this->text[$this->cursor] : null;
    }

    private function skipWhitespace(): void
    {
        while ($this->cursor < $this->length) {
            $character = $this->text[$this->cursor];

            if ($character === ' ' || $character === "\t" || $character === "\n" || $character === "\r") {
                $this->cursor++;

                continue;
            }

            break;
        }
    }

    private function violation(string $reason): GeoJsonViolation
    {
        return new GeoJsonViolation($this->cursor, $reason);
    }

    /**
     * The byte offset of the $index-th UTF-8 character, or the text length when
     * the string is shorter.
     */
    private function byteOffsetOfCharacter(string $text, int $index): int
    {
        $prefix = mb_substr($text, 0, $index, 'UTF-8');

        return strlen($prefix);
    }
}
