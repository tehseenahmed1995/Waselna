<?php

declare(strict_types=1);

/*
 * AddressNormalizer: the stable key and hash a geocode_cache row is stored under
 * (task 6.7; design Zone notes). Normalisation folds case and whitespace so the
 * same place submitted twice resolves once.
 */

use App\Modules\Zone\Domain\AddressNormalizer;
use App\Shared\Domain\PostalAddress;

it('folds case and collapses whitespace into one key', function (): void {
    $address = PostalAddress::of('  12   Market   Street ', null, 'RIYADH', null);

    expect(AddressNormalizer::key($address))->toBe('12 market street, riyadh');
});

it('gives two spellings of the same place one key and one hash', function (): void {
    $a = PostalAddress::of('12 Market Street', null, 'Riyadh', null);
    $b = PostalAddress::of('12   MARKET street', null, '  riyadh ', null);

    expect(AddressNormalizer::key($a))->toBe(AddressNormalizer::key($b))
        ->and(AddressNormalizer::hash($a))->toBe(AddressNormalizer::hash($b));
});

it('gives different addresses different hashes', function (): void {
    $a = PostalAddress::of('12 Market Street', null, 'Riyadh', null);
    $b = PostalAddress::of('13 Market Street', null, 'Riyadh', null);

    expect(AddressNormalizer::hash($a))->not->toBe(AddressNormalizer::hash($b));
});

it('produces a sha-256 hex digest', function (): void {
    $hash = AddressNormalizer::hash(PostalAddress::of('12 Market Street'));

    expect($hash)->toMatch('/^[0-9a-f]{64}$/');
});
