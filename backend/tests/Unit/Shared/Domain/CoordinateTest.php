<?php

declare(strict_types=1);

/*
 * Coordinate: fixed 7-digit decimal strings, the Requirement 40.4 range bounds
 * (-90 to 90 latitude, -180 to 180 longitude), and the Requirement 7.2 distance
 * rounded half up to 3 fractional digits.
 */

use App\Shared\Domain\Coordinate;

it('normalises both axes to exactly seven fractional digits', function (string $input, string $expected): void {
    expect(Coordinate::of($input, $input)->latitude)->toBe($expected);
})->with([
    'no fractional part' => ['24', '24.0000000'],
    'one fractional digit' => ['24.7', '24.7000000'],
    'already seven digits' => ['24.7136000', '24.7136000'],
    'explicit plus sign' => ['+24.7136', '24.7136000'],
    'negative' => ['-24.7136', '-24.7136000'],
    'negative zero loses its sign' => ['-0.0000000', '0.0000000'],
    'leading zeros are dropped' => ['0024.7136', '24.7136000'],
]);

it('rounds an eighth fractional digit half up', function (string $input, string $expected): void {
    expect(Coordinate::of($input, '0')->latitude)->toBe($expected);
})->with([
    'exactly half rounds up' => ['24.71360005', '24.7136001'],
    'just below half rounds down' => ['24.71360004', '24.7136000'],
    'just above half rounds up' => ['24.71360006', '24.7136001'],
    'carry crosses the separator' => ['24.99999995', '25.0000000'],
    'negative rounds away from zero' => ['-24.71360005', '-24.7136001'],
]);

it('accepts both axes at their range bounds', function (
    string $latitude,
    string $longitude,
    string $expectedLatitude,
    string $expectedLongitude,
): void {
    $coordinate = Coordinate::of($latitude, $longitude);

    expect($coordinate->latitude)->toBe($expectedLatitude)
        ->and($coordinate->longitude)->toBe($expectedLongitude);
})->with([
    'south pole and antimeridian west' => ['-90', '-180', '-90.0000000', '-180.0000000'],
    'north pole and antimeridian east' => ['90', '180', '90.0000000', '180.0000000'],
    'null island' => ['0', '0', '0.0000000', '0.0000000'],
    'just inside the bounds' => ['89.9999999', '179.9999999', '89.9999999', '179.9999999'],
]);

it('rejects a coordinate outside the range bounds', function (string $latitude, string $longitude): void {
    expect(static fn (): Coordinate => Coordinate::of($latitude, $longitude))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'latitude one unit above the maximum' => ['90.0000001', '0'],
    'latitude one unit below the minimum' => ['-90.0000001', '0'],
    'latitude far above' => ['91', '0'],
    'longitude one unit above the maximum' => ['0', '180.0000001'],
    'longitude one unit below the minimum' => ['0', '-180.0000001'],
    'longitude far below' => ['0', '-181'],
]);

it('accepts a value that rounds back into range at seven digits', function (): void {
    expect(Coordinate::of('90.00000004', '-180.00000004')->toArray())->toBe([
        'latitude' => '90.0000000',
        'longitude' => '-180.0000000',
    ]);
});

it('rejects anything that is not a plain decimal literal', function (string $value): void {
    expect(static fn (): Coordinate => Coordinate::of($value, '0'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'blank' => [''],
    'letters' => ['abc'],
    'exponent notation' => ['1e2'],
    'trailing separator' => ['24.'],
    'leading separator' => ['.7136'],
    'thousands separator' => ['1,234'],
    'padded with spaces' => [' 24.7 '],
]);

it('treats two coordinates as equal when both normalised axes match', function (): void {
    expect(Coordinate::of('24.7136', '46.6753')->equals(Coordinate::of('24.71360000', '46.67530000')))->toBeTrue()
        ->and(Coordinate::of('24.7136', '46.6753')->equals(Coordinate::of('24.7136001', '46.6753')))->toBeFalse()
        ->and(Coordinate::of('24.7136', '46.6753')->equals(Coordinate::of('46.6753', '24.7136')))->toBeFalse();
});

it('reports a zero distance to itself', function (): void {
    $coordinate = Coordinate::of('24.7136', '46.6753');

    expect($coordinate->haversineKilometres($coordinate))->toBe('0.000')
        ->and($coordinate->haversineKilometres(Coordinate::of('24.71360', '46.67530')))->toBe('0.000');
});

it('reports the distance in kilometres rounded to three fractional digits', function (
    string $fromLatitude,
    string $fromLongitude,
    string $toLatitude,
    string $toLongitude,
    string $expected,
): void {
    $from = Coordinate::of($fromLatitude, $fromLongitude);
    $to = Coordinate::of($toLatitude, $toLongitude);

    expect($from->haversineKilometres($to))->toBe($expected)
        ->and($to->haversineKilometres($from))->toBe($expected);
})->with([
    'one degree of longitude at the equator' => ['0', '0', '0', '1', '111.195'],
    'one degree of latitude' => ['0', '0', '1', '0', '111.195'],
    'Riyadh to Jeddah' => ['24.7136000', '46.6753000', '21.4858000', '39.1925000', '845.102'],
    'pole to pole' => ['90', '0', '-90', '0', '20015.114'],
    'half the equator' => ['0', '0', '0', '180', '20015.114'],
    'one unit of the last stored digit is below the rounding floor' => ['0', '0', '0.0000001', '0', '0.000'],
]);

it('always prints a distance with exactly three fractional digits', function (): void {
    $distance = Coordinate::of('24.7136', '46.6753')->haversineKilometres(Coordinate::of('24.8', '46.7'));

    expect($distance)->toMatch('/^\d+\.\d{3}$/');
});

it('returns the same distance for the same pair every time', function (): void {
    $from = Coordinate::of('24.7136', '46.6753');
    $to = Coordinate::of('21.4858', '39.1925');

    expect($from->haversineKilometres($to))->toBe($from->haversineKilometres($to));
});

it('orders a GeoJSON position longitude first', function (): void {
    expect(Coordinate::of('24.7136', '46.6753')->toGeoJsonPosition())->toBe(['46.6753000', '24.7136000']);
});

it('keeps the documented scales and range bounds', function (): void {
    expect(Coordinate::FRACTIONAL_DIGITS)->toBe(7)
        ->and(Coordinate::DISTANCE_FRACTIONAL_DIGITS)->toBe(3)
        ->and(Coordinate::MIN_LATITUDE)->toBe('-90')
        ->and(Coordinate::MAX_LATITUDE)->toBe('90')
        ->and(Coordinate::MIN_LONGITUDE)->toBe('-180')
        ->and(Coordinate::MAX_LONGITUDE)->toBe('180');
});
