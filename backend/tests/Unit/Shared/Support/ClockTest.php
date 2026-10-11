<?php

declare(strict_types=1);

/*
 * Clock: the single source of the current instant, always read in UTC
 * (Requirement 40.7), with a frozen implementation that makes every
 * time-dependent rule deterministic under test.
 */

use App\Shared\Support\Clock;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\SystemClock;

it('reads the system clock in UTC', function (): void {
    $clock = new SystemClock;

    expect($clock)->toBeInstanceOf(Clock::class)
        ->and($clock->nowUtc()->format('e'))->toBe('UTC');
});

it('holds the frozen instant still across reads', function (): void {
    $clock = FrozenClock::at('2025-01-01T00:00:00Z');

    expect($clock->nowUtc()->format('Y-m-d\TH:i:s\Z'))->toBe('2025-01-01T00:00:00Z')
        ->and($clock->nowUtc()->format('Y-m-d\TH:i:s\Z'))->toBe('2025-01-01T00:00:00Z');
});

it('converts an instant given in another time zone to UTC', function (): void {
    $clock = new FrozenClock(new DateTimeImmutable('2025-01-01T03:00:00', new DateTimeZone('+03:00')));

    expect($clock->nowUtc()->format('e'))->toBe('UTC')
        ->and($clock->nowUtc()->format('Y-m-d\TH:i:s\Z'))->toBe('2025-01-01T00:00:00Z');
});

it('advances by seconds, minutes, and days', function (Closure $advance, string $expected): void {
    $clock = FrozenClock::at('2025-01-01T00:00:00Z');

    $advance($clock);

    expect($clock->nowUtc()->format('Y-m-d\TH:i:s\Z'))->toBe($expected);
})->with([
    // The deadlines the fake exists to exercise.
    'the location timestamp window' => [
        static function (FrozenClock $clock): void {
            $clock->advanceSeconds(60);
        },
        '2025-01-01T00:01:00Z',
    ],
    'the Dispatch offer timer' => [
        static function (FrozenClock $clock): void {
            $clock->advanceSeconds(120);
        },
        '2025-01-01T00:02:00Z',
    ],
    'the sign-in lockout' => [
        static function (FrozenClock $clock): void {
            $clock->advanceMinutes(15);
        },
        '2025-01-01T00:15:00Z',
    ],
    'the Access_Token expiry' => [
        static function (FrozenClock $clock): void {
            $clock->advanceMinutes(60);
        },
        '2025-01-01T01:00:00Z',
    ],
    'the email-change window' => [
        static function (FrozenClock $clock): void {
            $clock->advanceDays(1);
        },
        '2025-01-02T00:00:00Z',
    ],
    'the Refresh_Token expiry' => [
        static function (FrozenClock $clock): void {
            $clock->advanceDays(30);
        },
        '2025-01-31T00:00:00Z',
    ],
]);

it('moves backward on a negative number of seconds', function (): void {
    $clock = FrozenClock::at('2025-01-01T00:00:00Z');
    $clock->advanceSeconds(-300);

    expect($clock->nowUtc()->format('Y-m-d\TH:i:s\Z'))->toBe('2024-12-31T23:55:00Z');
});

it('stands still on an advance of zero seconds', function (): void {
    $clock = FrozenClock::at('2025-01-01T00:00:00Z');
    $clock->advanceSeconds(0);

    expect($clock->nowUtc()->format('Y-m-d\TH:i:s\Z'))->toBe('2025-01-01T00:00:00Z');
});

it('advances by an interval', function (): void {
    $clock = FrozenClock::at('2025-01-01T00:00:00Z');
    $clock->advance(new DateInterval('PT1H30M'));

    expect($clock->nowUtc()->format('Y-m-d\TH:i:s\Z'))->toBe('2025-01-01T01:30:00Z');
});

it('refuses an inverted interval, directing the caller to negative seconds', function (): void {
    $clock = FrozenClock::at('2025-01-01T00:00:00Z');
    $backward = new DateInterval('PT1H');
    $backward->invert = 1;

    expect(static function () use ($clock, $backward): void {
        $clock->advance($backward);
    })->toThrow(InvalidArgumentException::class);
});

it('resets to any instant', function (): void {
    $clock = FrozenClock::at('2025-01-01T00:00:00Z');
    $clock->setTo(new DateTimeImmutable('2025-06-15T12:00:00', new DateTimeZone('+03:00')));

    expect($clock->nowUtc()->format('Y-m-d\TH:i:s\Z'))->toBe('2025-06-15T09:00:00Z');
});
