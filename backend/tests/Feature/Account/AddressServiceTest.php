<?php

declare(strict_types=1);

// Feature: waselna, task 4.6: AddressService create, default handling, archival, and listing.

/*
 * Example-based service tests for the Account module's AddressServiceImplementation
 * (task 4.6; Requirements 31.7–31.11). The exhaustive invariant check across
 * generated operation sequences is Property 70, written separately in task 4.7;
 * these tests pin the specific behaviours a reader of the requirements expects:
 *
 *  - the first saved address of a Customer is the default, a later one is not
 *    (Requirement 31.7);
 *  - the 20-address bound rejects the 21st with address_limit_reached and stores
 *    nothing (Requirement 31.8);
 *  - marking a non-default address default moves the single default and leaves an
 *    already-default target unchanged (Requirement 31.9);
 *  - deleting archives the address, excludes it from the list, and clears the
 *    default (Requirement 31.10);
 *  - the list returns only non-archived addresses, default first then by label
 *    and id, and an unknown or foreign id is the non-disclosing address_not_found
 *    (Requirement 31.11).
 *
 * The service resolves coordinates through the Zone AddressResolver seam; a fake
 * resolver stands in so the test neither geocodes nor touches the Zone database,
 * and so a chosen coordinate can be asserted on the stored row. The four Account
 * tables are built directly from the task 4.6 reshape migration's columns rather
 * than by running the PostGIS-bearing migration set, matching the Property
 * suite's SQLite approach. Time comes from a FrozenClock and identifiers from a
 * SequenceIdGenerator so creation order and archival instants are deterministic.
 */

use App\Modules\Account\Domain\CreateAddressCommand;
use App\Modules\Account\Repositories\AddressRepository;
use App\Modules\Account\Services\AddressServiceImplementation;
use App\Modules\Zone\Contracts\Services\AddressResolver;
use App\Modules\Zone\Domain\Exceptions\AddressNotResolvable;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Domain\PostalAddress;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\SequenceIdGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A deterministic AddressResolver double. It returns a fixed coordinate unless
 * the first line is the documented unresolvable sentinel, in which case it
 * raises exactly as the local adapter does, so the no-store-on-no-match path can
 * be exercised without the Zone module.
 */
function fakeResolver(string $latitude = '10.0000000', string $longitude = '20.0000000'): AddressResolver
{
    return new class($latitude, $longitude) implements AddressResolver
    {
        public function __construct(private string $latitude, private string $longitude) {}

        public function resolve(PostalAddress $address): Coordinate
        {
            if (mb_strtolower($address->line1) === 'unresolvable') {
                throw AddressNotResolvable::noMatch();
            }

            return new Coordinate($this->latitude, $this->longitude);
        }
    };
}

function addressServiceUnderTest(?AddressResolver $resolver = null): AddressServiceImplementation
{
    return new AddressServiceImplementation(
        new AddressRepository,
        $resolver ?? fakeResolver(),
        FrozenClock::at('2025-03-01T12:00:00Z'),
        new SequenceIdGenerator,
    );
}

function createAddressSchema(): void
{
    Schema::dropIfExists('delivery_addresses');
    Schema::dropIfExists('users');

    Schema::create('users', function ($table): void {
        $table->string('id')->primary();
        $table->string('email', 254);
        $table->string('email_lower', 254)->unique();
        $table->string('display_name', 100)->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    Schema::create('delivery_addresses', function ($table): void {
        $table->string('id')->primary();
        $table->string('user_id');
        $table->string('label', 50);
        $table->text('line1_encrypted');
        $table->text('line2_encrypted')->nullable();
        $table->text('locality_encrypted');
        $table->text('postal_code_encrypted');
        $table->char('country_code', 2);
        $table->decimal('latitude', 10, 7);
        $table->decimal('longitude', 10, 7);
        $table->boolean('is_default')->default(false);
        $table->timestamp('archived_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
}

function seedCustomer(): string
{
    $id = (string) Str::ulid();
    $email = 'customer+'.Str::lower(Str::random(10)).'@waselna.test';

    DB::table('users')->insert([
        'id' => $id,
        'email' => $email,
        'email_lower' => Str::lower($email),
        'display_name' => 'Customer',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function makeCreate(string $userId, string $label, string $line1 = 'Line one'): CreateAddressCommand
{
    return new CreateAddressCommand(
        userId: $userId,
        label: $label,
        line1: $line1,
        line2: null,
        locality: 'Metropolis',
        postalCode: '12345',
        countryCode: 'sa',
    );
}

beforeEach(function (): void {
    createAddressSchema();
});

it('makes the first saved address the default and a later one not', function (): void {
    $service = addressServiceUnderTest();
    $userId = seedCustomer();

    $first = $service->create(makeCreate($userId, 'Home'));
    $second = $service->create(makeCreate($userId, 'Work'));

    expect($first->isDefault)->toBeTrue()
        ->and($second->isDefault)->toBeFalse()
        // country_code is normalised to upper case (Requirement 31.7).
        ->and($first->countryCode)->toBe('SA')
        // The Zone-resolved coordinate is stored verbatim.
        ->and($first->latitude)->toBe('10.0000000')
        ->and($first->longitude)->toBe('20.0000000');

    // Exactly one non-archived default exists.
    $defaults = DB::table('delivery_addresses')
        ->where('user_id', $userId)
        ->whereNull('archived_at')
        ->where('is_default', true)
        ->count();

    expect($defaults)->toBe(1);
});

it('rejects the 21st non-archived address and stores nothing', function (): void {
    $service = addressServiceUnderTest();
    $userId = seedCustomer();

    for ($i = 1; $i <= AddressServiceImplementation::MAX_ACTIVE_ADDRESSES; $i++) {
        $service->create(makeCreate($userId, 'Label '.$i));
    }

    expect(fn () => $service->create(makeCreate($userId, 'One too many')))
        ->toThrow(ValidationException::class);

    $count = DB::table('delivery_addresses')->where('user_id', $userId)->count();
    expect($count)->toBe(AddressServiceImplementation::MAX_ACTIVE_ADDRESSES);
});

it('does not count archived addresses toward the 20-address bound', function (): void {
    $service = addressServiceUnderTest();
    $userId = seedCustomer();

    $first = $service->create(makeCreate($userId, 'Home'));
    $service->archive($userId, $first->id);

    // 20 more should still be creatable because the archived one does not count.
    for ($i = 1; $i <= AddressServiceImplementation::MAX_ACTIVE_ADDRESSES; $i++) {
        $service->create(makeCreate($userId, 'Label '.$i));
    }

    $active = (new AddressRepository)->countActive($userId);
    expect($active)->toBe(AddressServiceImplementation::MAX_ACTIVE_ADDRESSES);
});

it('does not store an address whose coordinate does not resolve', function (): void {
    $service = addressServiceUnderTest();
    $userId = seedCustomer();

    expect(fn () => $service->create(makeCreate($userId, 'Home', 'UNRESOLVABLE')))
        ->toThrow(AddressNotResolvable::class);

    expect(DB::table('delivery_addresses')->where('user_id', $userId)->count())->toBe(0);
});

it('moves the single default when another address is marked default', function (): void {
    $service = addressServiceUnderTest();
    $userId = seedCustomer();

    $home = $service->create(makeCreate($userId, 'Home'));
    $work = $service->create(makeCreate($userId, 'Work'));

    $service->markDefault($userId, $work->id);

    $rows = DB::table('delivery_addresses')->where('user_id', $userId)->pluck('is_default', 'id');

    expect((bool) $rows[$home->id])->toBeFalse()
        ->and((bool) $rows[$work->id])->toBeTrue();
});

it('leaves everything unchanged when marking an already-default address default', function (): void {
    $service = addressServiceUnderTest();
    $userId = seedCustomer();

    $home = $service->create(makeCreate($userId, 'Home'));

    $service->markDefault($userId, $home->id);

    $defaults = DB::table('delivery_addresses')
        ->where('user_id', $userId)->where('is_default', true)->count();

    expect($defaults)->toBe(1);
});

it('archives on delete, excludes from the list, and clears the default', function (): void {
    $service = addressServiceUnderTest();
    $userId = seedCustomer();

    $home = $service->create(makeCreate($userId, 'Home'));

    $service->archive($userId, $home->id);

    // Excluded from the list (Requirement 31.10, 31.11).
    expect($service->listOwn($userId))->toBe([]);

    // Default cleared; the row survives, archived (Requirement 31.10).
    $row = DB::table('delivery_addresses')->where('id', $home->id)->first();
    expect($row)->not->toBeNull();
    assert($row !== null);
    expect((bool) $row->is_default)->toBeFalse()
        ->and($row->archived_at)->not->toBeNull();
});

it('makes a repeated delete change nothing by returning the non-disclosing 404', function (): void {
    $service = addressServiceUnderTest();
    $userId = seedCustomer();

    $home = $service->create(makeCreate($userId, 'Home'));
    $service->archive($userId, $home->id);

    expect(fn () => $service->archive($userId, $home->id))
        ->toThrow(AuthorizationException::class);
});

it('orders the list with the default first, then by label and id', function (): void {
    $service = addressServiceUnderTest();
    $userId = seedCustomer();

    // First created is default; give it a label that would otherwise sort last.
    $zed = $service->create(makeCreate($userId, 'Zed'));   // default
    $alpha = $service->create(makeCreate($userId, 'Alpha'));
    $beta = $service->create(makeCreate($userId, 'Beta'));

    $order = array_map(static fn ($v) => $v->id, $service->listOwn($userId));

    // Default (Zed) first despite its label, then Alpha, then Beta.
    expect($order)->toBe([$zed->id, $alpha->id, $beta->id]);
});

it('returns the non-disclosing 404 for an unknown or foreign address id', function (): void {
    $service = addressServiceUnderTest();
    $userId = seedCustomer();
    $otherUserId = seedCustomer();

    $foreign = $service->create(makeCreate($otherUserId, 'Home'));

    // Unknown id.
    expect(fn () => $service->markDefault($userId, (string) Str::ulid()))
        ->toThrow(AuthorizationException::class);

    // Another Customer's id reads identically.
    expect(fn () => $service->markDefault($userId, $foreign->id))
        ->toThrow(AuthorizationException::class);
});

it('exposes a decrypted snapshot of an owned address through the directory boundary', function (): void {
    $service = addressServiceUnderTest();
    $userId = seedCustomer();

    $home = $service->create(makeCreate($userId, 'Home'));

    $snapshot = $service->findOwnedAddress($userId, $home->id);

    expect($snapshot)->not->toBeNull();
    assert($snapshot !== null);
    expect($snapshot->line1)->toBe('Line one')
        ->and($snapshot->locality)->toBe('Metropolis')
        ->and($snapshot->countryCode)->toBe('SA')
        ->and($snapshot->coordinate->latitude)->toBe('10.0000000');

    // An archived address is not exposed through the boundary.
    $service->archive($userId, $home->id);
    expect($service->findOwnedAddress($userId, $home->id))->toBeNull();
});
