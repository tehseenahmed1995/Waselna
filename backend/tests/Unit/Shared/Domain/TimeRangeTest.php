<?php

declare(strict_types=1);

/*
 * TimeRange: a half-open interval [start, end) of UTC instants
 * (Requirements 37.4, 40.7, 20.8).
 */

use App\Shared\Domain\TimeRange;

/**
 * A UTC instant, named distinctly so it stays local to this file's suite.
 */
function timeRangeInstant(string $instant): DateTimeImmutable
{
    return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
}

it('holds both bounds as UTC instants', function (): void {
    $range = TimeRange::between(
        new DateTimeImmutable('2025-01-01T03:00:00', new DateTimeZone('+03:00')),
        new DateTimeImmutable('2025-01-01T06:00:00', new DateTimeZone('+03:00')),
    );

    expect($range->start->format('e'))->toBe('UTC')
        ->and($range->end->format('e'))->toBe('UTC')
        ->and($range->toArray())->toBe([
            'start' => '2025-01-01T00:00:00Z',
            'end' => '2025-01-01T03:00:00Z',
        ]);
});

it('requires the start to fall strictly before the end', function (string $start, string $end): void {
    expect(static fn (): TimeRange => TimeRange::between(timeRangeInstant($start), timeRangeInstant($end)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'equal bounds' => ['2025-01-01T00:00:00Z', '2025-01-01T00:00:00Z'],
    'reversed bounds' => ['2025-01-01T00:00:01Z', '2025-01-01T00:00:00Z'],
]);

it('includes its start and excludes its end', function (string $instant, bool $expected): void {
    $range = TimeRange::between(timeRangeInstant('2025-01-01T10:00:00Z'), timeRangeInstant('2025-01-01T12:00:00Z'));

    expect($range->contains(timeRangeInstant($instant)))->toBe($expected);
})->with([
    'one second before the start' => ['2025-01-01T09:59:59Z', false],
    'exactly the start' => ['2025-01-01T10:00:00Z', true],
    'inside' => ['2025-01-01T11:00:00Z', true],
    'one second before the end' => ['2025-01-01T11:59:59Z', true],
    'exactly the end' => ['2025-01-01T12:00:00Z', false],
    'after the end' => ['2025-01-01T12:00:01Z', false],
]);

it('evaluates containment of an instant given in another time zone', function (): void {
    $range = TimeRange::between(timeRangeInstant('2025-01-01T10:00:00Z'), timeRangeInstant('2025-01-01T12:00:00Z'));

    expect($range->contains(new DateTimeImmutable('2025-01-01T14:00:00', new DateTimeZone('+03:00'))))->toBeTrue()
        ->and($range->contains(new DateTimeImmutable('2025-01-01T10:00:00', new DateTimeZone('+03:00'))))->toBeFalse();
});

it('treats back-to-back ranges as non-overlapping', function (): void {
    $morning = TimeRange::between(timeRangeInstant('2025-01-01T08:00:00Z'), timeRangeInstant('2025-01-01T12:00:00Z'));
    $afternoon = TimeRange::between(timeRangeInstant('2025-01-01T12:00:00Z'), timeRangeInstant('2025-01-01T16:00:00Z'));

    expect($morning->overlaps($afternoon))->toBeFalse()
        ->and($afternoon->overlaps($morning))->toBeFalse();
});

it('detects overlapping ranges symmetrically', function (string $start, string $end, bool $expected): void {
    $range = TimeRange::between(timeRangeInstant('2025-01-01T10:00:00Z'), timeRangeInstant('2025-01-01T12:00:00Z'));
    $other = TimeRange::between(timeRangeInstant($start), timeRangeInstant($end));

    expect($range->overlaps($other))->toBe($expected)
        ->and($other->overlaps($range))->toBe($expected);
})->with([
    'entirely before' => ['2025-01-01T08:00:00Z', '2025-01-01T09:00:00Z', false],
    'touching before' => ['2025-01-01T08:00:00Z', '2025-01-01T10:00:00Z', false],
    'overlapping the start' => ['2025-01-01T09:00:00Z', '2025-01-01T10:00:01Z', true],
    'nested' => ['2025-01-01T10:30:00Z', '2025-01-01T11:30:00Z', true],
    'identical' => ['2025-01-01T10:00:00Z', '2025-01-01T12:00:00Z', true],
    'enclosing' => ['2025-01-01T09:00:00Z', '2025-01-01T13:00:00Z', true],
    'overlapping the end' => ['2025-01-01T11:59:59Z', '2025-01-01T13:00:00Z', true],
    'touching after' => ['2025-01-01T12:00:00Z', '2025-01-01T13:00:00Z', false],
    'entirely after' => ['2025-01-01T13:00:00Z', '2025-01-01T14:00:00Z', false],
]);

it('reports its duration in seconds', function (): void {
    expect(TimeRange::between(timeRangeInstant('2025-01-01T10:00:00Z'), timeRangeInstant('2025-01-01T10:00:01Z'))->durationSeconds())->toBe(1)
        ->and(TimeRange::between(timeRangeInstant('2025-01-01T00:00:00Z'), timeRangeInstant('2025-01-02T00:00:00Z'))->durationSeconds())->toBe(86400);
});

it('treats two ranges as equal when both instants match, whatever zone they were given in', function (): void {
    $utcRange = TimeRange::between(timeRangeInstant('2025-01-01T00:00:00Z'), timeRangeInstant('2025-01-01T03:00:00Z'));
    $offsetRange = TimeRange::between(
        new DateTimeImmutable('2025-01-01T03:00:00', new DateTimeZone('+03:00')),
        new DateTimeImmutable('2025-01-01T06:00:00', new DateTimeZone('+03:00')),
    );

    expect($utcRange->equals($offsetRange))->toBeTrue()
        ->and($utcRange->equals(TimeRange::between(timeRangeInstant('2025-01-01T00:00:00Z'), timeRangeInstant('2025-01-01T03:00:01Z'))))->toBeFalse();
});

it('distinguishes ranges differing only in sub-second precision', function (): void {
    $range = TimeRange::between(timeRangeInstant('2025-01-01T00:00:00.000000Z'), timeRangeInstant('2025-01-01T03:00:00Z'));
    $shifted = TimeRange::between(timeRangeInstant('2025-01-01T00:00:00.500000Z'), timeRangeInstant('2025-01-01T03:00:00Z'));

    expect($range->equals($shifted))->toBeFalse();
});

it('prints ISO 8601 UTC bounds at second precision', function (): void {
    $range = TimeRange::between(timeRangeInstant('2025-01-01T10:15:30.987654Z'), timeRangeInstant('2025-12-31T23:59:59Z'));

    expect($range->toArray())->toBe([
        'start' => '2025-01-01T10:15:30Z',
        'end' => '2025-12-31T23:59:59Z',
    ]);
});
