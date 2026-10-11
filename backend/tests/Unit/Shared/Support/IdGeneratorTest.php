<?php

declare(strict_types=1);

/*
 * IdGenerator: canonical 26-character Crockford base32 identifiers
 * (Requirement 40.7), with a sequence implementation that lets a test know an
 * identifier before the code under test creates it.
 */

use App\Shared\Support\IdGenerator;
use App\Shared\Support\SequenceIdGenerator;
use App\Shared\Support\UlidGenerator;

/**
 * The canonical form: 26 Crockford base32 digits, excluding I, L, O, and U.
 */
function identifierPattern(): string
{
    return '/^[0-9A-HJKMNP-TV-Z]{26}$/';
}

/**
 * A canonical identifier ending in $suffix, zero-padded to 26 characters.
 */
function identifierEndingIn(string $suffix): string
{
    return str_pad($suffix, 26, '0', STR_PAD_LEFT);
}

it('produces a canonical ULID in production', function (): void {
    $generator = new UlidGenerator;

    expect($generator)->toBeInstanceOf(IdGenerator::class)
        ->and($generator->ulid())->toMatch(identifierPattern())
        ->and($generator->ulid())->not->toBe($generator->ulid());
});

it('produces canonical identifiers from the seeded sequence', function (): void {
    $generator = new SequenceIdGenerator;

    expect($generator)->toBeInstanceOf(IdGenerator::class)
        ->and($generator->ulid())->toMatch(identifierPattern())
        ->and($generator->ulid())->toMatch(identifierPattern());
});

it('hands out an ascending, collision-free sequence', function (): void {
    $generator = new SequenceIdGenerator;
    $identifiers = [$generator->ulid(), $generator->ulid(), $generator->ulid()];

    $sorted = $identifiers;
    sort($sorted);

    expect($identifiers)->toBe($sorted)
        ->and(array_unique($identifiers))->toHaveCount(3)
        ->and($generator->issuedCount())->toBe(3);
});

it('sorts the seeded sequence below a real ULID', function (): void {
    expect((new SequenceIdGenerator)->ulid() < (new UlidGenerator)->ulid())->toBeTrue();
});

it('names the nth identifier without consuming it', function (): void {
    $generator = new SequenceIdGenerator;

    $expected = $generator->peek(1);

    expect($generator->issuedCount())->toBe(0)
        ->and($generator->ulid())->toBe($expected)
        ->and($generator->peek(2))->toBe($generator->ulid());
});

it('rejects a peek before the first position', function (int $position): void {
    expect(static fn (): string => (new SequenceIdGenerator)->peek($position))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'zero' => [0],
    'negative' => [-1],
]);

it('carries across the base32 digit boundary', function (): void {
    $generator = new SequenceIdGenerator;

    expect($generator->peek(31))->toBe(identifierEndingIn('Z'))
        ->and($generator->peek(32))->toBe(identifierEndingIn('10'))
        ->and($generator->peek(32))->toMatch(identifierPattern());
});

it('hands out queued identifiers ahead of the sequence, twice over if asked', function (): void {
    $generator = new SequenceIdGenerator;
    $collision = identifierEndingIn('X');

    $generator->queue($collision, $collision);

    expect($generator->ulid())->toBe($collision)
        ->and($generator->ulid())->toBe($collision)
        ->and($generator->issuedCount())->toBe(0)
        ->and($generator->ulid())->toBe(identifierEndingIn('1'));
});

it('rejects a queued identifier that is not canonical', function (string $identifier): void {
    expect(static function () use ($identifier): void {
        (new SequenceIdGenerator)->queue($identifier);
    })->toThrow(InvalidArgumentException::class);
})->with([
    'too short' => [str_repeat('0', 25)],
    'too long' => [str_repeat('0', 27)],
    'lowercase' => [identifierEndingIn('x')],
    'excluded letter I' => [identifierEndingIn('I')],
    'excluded letter L' => [identifierEndingIn('L')],
    'excluded letter O' => [identifierEndingIn('O')],
    'excluded letter U' => [identifierEndingIn('U')],
    'hyphenated' => [identifierEndingIn('-1')],
    'blank' => [''],
]);

it('returns to its starting state on reset', function (): void {
    $generator = new SequenceIdGenerator;
    $first = $generator->ulid();
    $generator->queue(identifierEndingIn('X'));

    $generator->reset();

    expect($generator->issuedCount())->toBe(0)
        ->and($generator->ulid())->toBe($first);
});
