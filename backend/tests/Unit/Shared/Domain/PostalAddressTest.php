<?php

declare(strict_types=1);

/*
 * PostalAddress: the framework-free address value object the AddressResolver
 * resolves (Requirements 5.6, 5.7, 32.14). It requires a non-empty first line,
 * folds blank optional parts to null, and exposes the non-blank parts in a
 * stable order for the Zone module's normalized cache key.
 */

use App\Shared\Domain\PostalAddress;

it('keeps a trimmed first line and the supplied optional parts', function (): void {
    $address = PostalAddress::of('  12 Market Street  ', 'Suite 4', 'Riyadh', '11564');

    expect($address->line1)->toBe('12 Market Street')
        ->and($address->line2)->toBe('Suite 4')
        ->and($address->city)->toBe('Riyadh')
        ->and($address->postalCode)->toBe('11564');
});

it('folds blank or whitespace-only optional parts to null', function (?string $blank): void {
    $address = PostalAddress::of('12 Market Street', $blank, $blank, $blank);

    expect($address->line2)->toBeNull()
        ->and($address->city)->toBeNull()
        ->and($address->postalCode)->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
    'spaces only' => ['   '],
]);

it('rejects a first line that is empty after trimming', function (string $blank): void {
    expect(static fn () => PostalAddress::of($blank))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty string' => [''],
    'spaces only' => ['   '],
]);

it('lists only the non-blank parts in a stable order', function (): void {
    expect(PostalAddress::of('12 Market Street', null, 'Riyadh', null)->parts())
        ->toBe(['12 Market Street', 'Riyadh']);
});
