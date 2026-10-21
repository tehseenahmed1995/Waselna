<?php

declare(strict_types=1);

// Feature: waselna, Property 19: Unserviceable and unresolvable addresses are rejected without side effects

/*
 * *For any* postal address that the address resolver cannot match or that
 * exceeds the 3,000-millisecond resolution budget, the request is rejected with
 * `address_not_resolvable` and no Delivery_Address and no Restaurant_Branch is
 * created or changed; and *for any* resolved coordinate pair outside the
 * Serviceable_Area of the Cart's Restaurant, the request is rejected with
 * `address_not_serviceable` and the Cart contents are unchanged. Every
 * coordinate pair the resolver does return carries a latitude in -90 to 90, a
 * longitude in -180 to 180, and at most 7 fractional digits.
 *
 * **Validates: Requirements 5.6, 5.7, 5.10, 32.14**
 *
 * Scope and suite placement. This property lives in the Property suite because
 * the paths it exercises — the `LocalDeterministicAddressResolver` resolution,
 * the documented no-match sentinel, the Clock-driven budget, and the
 * composition order of `DefaultServiceableAddressResolver` — touch only the
 * plain-SQL `geocode_cache` table and in-memory doubles, never PostGIS. The
 * PostGIS half of serviceability (a real union of Delivery_Zones) is pinned in
 * the Integration suite (UnserviceableAddressRejectionTest, Property 19) where a
 * reachable PostGIS container exists; here serviceability is driven through a
 * `ServiceabilityService` double so the rejection ordering and the
 * no-side-effect guarantee are provable without the spatial engine.
 *
 * Clock, not wall time. The 3,000-millisecond budget is measured through the
 * injected Clock (Requirement 40.7), so the budget-exceeded branch of
 * Requirement 5.6 is driven here by an advancing clock double rather than by
 * sleeping: the resolver reads the clock once at entry and once before storing,
 * and the double reports a generated elapsed span between the two readings. No
 * assertion in this file depends on real elapsed time.
 *
 * No side effects. The one observable write the resolver can make is a
 * `geocode_cache` row. Every rejection path asserts the row count is unchanged
 * across the attempt, which is the Property-suite-local stand-in for "no
 * Delivery_Address and no Restaurant_Branch created or changed" and "the Cart
 * contents are unchanged": those higher-level writes all sit downstream of the
 * resolver, which never returns, so they are never reached. Each generated input
 * runs inside a transaction rolled back afterwards, as the Testing Strategy
 * requires of database-touching properties.
 */

use App\Modules\Zone\Contracts\Services\AddressResolver;
use App\Modules\Zone\Contracts\Services\ServiceabilityService;
use App\Modules\Zone\Domain\AddressNormalizer;
use App\Modules\Zone\Domain\Exceptions\AddressNotResolvable;
use App\Modules\Zone\Domain\Exceptions\AddressNotServiceable;
use App\Modules\Zone\Repositories\GeocodeCacheRepository;
use App\Modules\Zone\Services\DefaultServiceableAddressResolver;
use App\Modules\Zone\Services\LocalDeterministicAddressResolver;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\Decimal;
use App\Shared\Domain\PostalAddress;
use App\Shared\Support\Clock;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\SequenceIdGenerator;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(TestTrait::class);

/**
 * Creates the plain-SQL `geocode_cache` table on the active (SQLite) connection.
 *
 * The task 6.5 migration also builds the PostGIS `delivery_zones` table, which
 * SQLite cannot create, so this reproduces only the one table this property
 * needs, column-for-column with the migration (ULID key, 64-char unique
 * `address_hash`, decimal(10,7) coordinates, a resolved-at timestamp). That is
 * exactly the design's point that the geocode cache is exercisable without the
 * PostGIS container.
 */
beforeEach(function (): void {
    Schema::create('geocode_cache', function ($table): void {
        $table->ulid('id')->primary();
        $table->string('address_hash', 64);
        $table->decimal('latitude', 10, 7);
        $table->decimal('longitude', 10, 7);
        $table->timestampTz('resolved_at');
        $table->unique('address_hash');
    });
});

afterEach(function (): void {
    Schema::dropIfExists('geocode_cache');
});

/**
 * A Clock that reports a fixed elapsed span between its first reading and every
 * later one, so the resolver's budget measurement is deterministic.
 *
 * The resolver reads the clock once at entry (`startedAt`) and once before the
 * cache write (`finishedAt`); this double returns the base instant on the first
 * call and the base instant advanced by $elapsedMilliseconds on every call
 * after, which is precisely the elapsed span the budget check subtracts. A span
 * at or below the 3,000 ms budget resolves; a span above it trips
 * `address_not_resolvable` through the budget branch.
 */
final class AdvancingBudgetClock implements Clock
{
    private int $readings = 0;

    public function __construct(
        private readonly FrozenClock $base,
        private readonly int $elapsedMilliseconds,
    ) {}

    public function nowUtc(): \DateTimeImmutable
    {
        $instant = $this->base->nowUtc();

        $afterFirstReading = $this->readings > 0;
        $this->readings++;

        if (! $afterFirstReading) {
            return $instant;
        }

        $microseconds = $this->elapsedMilliseconds * 1_000;

        return $instant->modify(sprintf('+%d microseconds', $microseconds));
    }
}

/**
 * A ServiceabilityService double returning a fixed decision, so the composition
 * in DefaultServiceableAddressResolver is exercised without PostGIS.
 */
final class FixedServiceability implements ServiceabilityService
{
    public function __construct(private readonly bool $serviceable) {}

    public function isServiceable(string $restaurantId, Coordinate $at): bool
    {
        return $this->serviceable;
    }

    public function servicingBranchIds(string $restaurantId, Coordinate $at): array
    {
        return $this->serviceable ? ['01BRANCHSERVICING000000000'] : [];
    }
}

/**
 * A real GeocodeCacheRepository over the SQLite connection with deterministic
 * identifiers and a frozen clock, so the cache round-trip is exact.
 */
function budgetCacheRepository(): GeocodeCacheRepository
{
    return new GeocodeCacheRepository(
        new SequenceIdGenerator(),
        FrozenClock::at('2025-01-01T00:00:00Z'),
    );
}

function geocodeRowCount(): int
{
    return (int) DB::table('geocode_cache')->count();
}

/**
 * A short printable address fragment: 1 to 20 characters drawn from letters,
 * digits, and spaces, never equal to the no-match sentinel. Used to build the
 * resolvable addresses whose coordinates the property inspects.
 */
function addressFragment(): ErisGenerator
{
    return Generators::map(
        static function (string $raw): string {
            $trimmed = trim($raw);
            $value = $trimmed === '' ? 'street' : $trimmed;

            // Never collide with the documented no-match sentinel.
            return mb_strtolower($value) === mb_strtolower(LocalDeterministicAddressResolver::UNRESOLVABLE_SENTINEL)
                ? $value.' road'
                : $value;
        },
        Generators::string(),
    );
}

/**
 * A PostalAddress built from generated parts, guaranteed resolvable (its first
 * line is never the sentinel).
 */
function resolvableAddress(): ErisGenerator
{
    return Generators::map(
        static fn (array $parts): PostalAddress => PostalAddress::of(
            $parts[0],
            $parts[1] === '' ? null : $parts[1],
            $parts[2] === '' ? null : $parts[2],
            $parts[3] === '' ? null : $parts[3],
        ),
        Generators::tuple(
            addressFragment(),
            Generators::oneOf(Generators::constant(''), addressFragment()),
            Generators::oneOf(Generators::constant(''), addressFragment()),
            Generators::oneOf(Generators::constant(''), addressFragment()),
        ),
    );
}

it('resolves within budget to a coordinate inside the valid ranges and caches once', function (): void {
    $this->limitTo(100)
        ->forAll(
            resolvableAddress(),
            // Elapsed spans at or under the 3,000 ms budget: resolution succeeds.
            Generators::choose(0, AddressResolver::RESOLUTION_BUDGET_MILLISECONDS),
        )
        ->then(function (PostalAddress $address, int $elapsedMilliseconds): void {
            DB::beginTransaction();

            try {
                $clock = new AdvancingBudgetClock(
                    FrozenClock::at('2025-01-01T00:00:00Z'),
                    $elapsedMilliseconds,
                );
                $resolver = new LocalDeterministicAddressResolver(budgetCacheRepository(), $clock);

                expect(geocodeRowCount())->toBe(0);

                $coordinate = $resolver->resolve($address);

                // Coordinate invariants (Requirement 5.6): the resolved pair lies
                // in the closed WGS 84 ranges at no more than 7 fractional digits.
                expect(resolverDecimalInRange($coordinate->latitude, '-90', '90'))->toBeTrue()
                    ->and(resolverDecimalInRange($coordinate->longitude, '-180', '180'))->toBeTrue()
                    ->and(resolverFractionalDigitCount($coordinate->latitude))->toBeLessThanOrEqual(7)
                    ->and(resolverFractionalDigitCount($coordinate->longitude))->toBeLessThanOrEqual(7);

                // A successful resolution writes exactly one cache row, and a
                // repeat of the same address reuses it rather than writing again.
                expect(geocodeRowCount())->toBe(1);

                $again = $resolver->resolve($address);

                expect($again->equals($coordinate))->toBeTrue()
                    ->and(geocodeRowCount())->toBe(1);
            } finally {
                DB::rollBack();
            }
        });
});

it('rejects an over-budget resolution with address_not_resolvable and writes nothing', function (): void {
    $this->limitTo(100)
        ->forAll(
            resolvableAddress(),
            // Elapsed spans strictly above the budget: resolution is rejected.
            Generators::choose(
                AddressResolver::RESOLUTION_BUDGET_MILLISECONDS + 1,
                AddressResolver::RESOLUTION_BUDGET_MILLISECONDS + 10_000,
            ),
        )
        ->then(function (PostalAddress $address, int $elapsedMilliseconds): void {
            DB::beginTransaction();

            try {
                $clock = new AdvancingBudgetClock(
                    FrozenClock::at('2025-01-01T00:00:00Z'),
                    $elapsedMilliseconds,
                );
                $resolver = new LocalDeterministicAddressResolver(budgetCacheRepository(), $clock);

                expect(geocodeRowCount())->toBe(0);

                try {
                    $resolver->resolve($address);
                    throw new RuntimeException('Expected AddressNotResolvable for an over-budget resolution.');
                } catch (AddressNotResolvable $rejection) {
                    expect($rejection->errorCode())->toBe('address_not_resolvable')
                        ->and($rejection->httpStatus())->toBe(422);
                }

                // The budget branch raises before the cache write (Requirement 5.7).
                expect(geocodeRowCount())->toBe(0);
            } finally {
                DB::rollBack();
            }
        });
});

it('rejects the no-match sentinel with address_not_resolvable and writes nothing', function (): void {
    $this->limitTo(100)
        ->forAll(
            // The documented sentinel, in any letter case and with any padding,
            // forces the no-match branch of Requirement 5.7.
            Generators::elements('UNRESOLVABLE', 'unresolvable', '  Unresolvable  ', 'uNRESOLVABLE'),
            Generators::choose(0, AddressResolver::RESOLUTION_BUDGET_MILLISECONDS),
        )
        ->then(function (string $sentinel, int $elapsedMilliseconds): void {
            DB::beginTransaction();

            try {
                $clock = new AdvancingBudgetClock(
                    FrozenClock::at('2025-01-01T00:00:00Z'),
                    $elapsedMilliseconds,
                );
                $resolver = new LocalDeterministicAddressResolver(budgetCacheRepository(), $clock);

                expect(geocodeRowCount())->toBe(0);

                try {
                    $resolver->resolve(PostalAddress::of($sentinel, 'Apt 1', 'Riyadh', '11564'));
                    throw new RuntimeException('Expected AddressNotResolvable for the no-match sentinel.');
                } catch (AddressNotResolvable $rejection) {
                    expect($rejection->errorCode())->toBe('address_not_resolvable')
                        ->and($rejection->httpStatus())->toBe(422);
                }

                // A no-match is rejected before any hashing or cache write.
                expect(geocodeRowCount())->toBe(0);
            } finally {
                DB::rollBack();
            }
        });
});

it('rejects a resolved but unserviceable address with address_not_serviceable and resolution order holds', function (): void {
    $this->limitTo(100)
        ->forAll(resolvableAddress())
        ->then(function (PostalAddress $address): void {
            DB::beginTransaction();

            try {
                $clock = new AdvancingBudgetClock(FrozenClock::at('2025-01-01T00:00:00Z'), 0);
                $resolver = new LocalDeterministicAddressResolver(budgetCacheRepository(), $clock);

                // The address resolves; serviceability then reports it outside the
                // Restaurant's Serviceable_Area (an empty or excluding union).
                $gated = new DefaultServiceableAddressResolver(
                    $resolver,
                    new FixedServiceability(serviceable: false),
                );

                try {
                    $gated->resolveServiceable('01RESTAURANT0000000000000', $address);
                    throw new RuntimeException('Expected AddressNotServiceable for an out-of-area coordinate.');
                } catch (AddressNotServiceable $rejection) {
                    expect($rejection->errorCode())->toBe('address_not_serviceable')
                        ->and($rejection->httpStatus())->toBe(422);
                }

                // Resolution ran first (it must, to produce the coordinate the
                // serviceability check rejected), so exactly the one cache row the
                // resolution wrote exists; the serviceability rejection itself adds
                // nothing (Requirement 5.10, 32.14).
                expect(geocodeRowCount())->toBe(1);
            } finally {
                DB::rollBack();
            }
        });
});

it('returns the resolved coordinate when the address is both resolvable and serviceable', function (): void {
    $this->limitTo(100)
        ->forAll(resolvableAddress())
        ->then(function (PostalAddress $address): void {
            DB::beginTransaction();

            try {
                $clock = new AdvancingBudgetClock(FrozenClock::at('2025-01-01T00:00:00Z'), 0);
                $resolver = new LocalDeterministicAddressResolver(budgetCacheRepository(), $clock);
                $gated = new DefaultServiceableAddressResolver(
                    $resolver,
                    new FixedServiceability(serviceable: true),
                );

                $coordinate = $gated->resolveServiceable('01RESTAURANT0000000000000', $address);
                $direct = (new LocalDeterministicAddressResolver(budgetCacheRepository(), $clock))->resolve($address);

                // The gate returns exactly the resolver's coordinate, and that
                // coordinate obeys the range and precision invariants.
                expect($coordinate->equals($direct))->toBeTrue()
                    ->and(resolverDecimalInRange($coordinate->latitude, '-90', '90'))->toBeTrue()
                    ->and(resolverDecimalInRange($coordinate->longitude, '-180', '180'))->toBeTrue()
                    ->and(resolverFractionalDigitCount($coordinate->latitude))->toBeLessThanOrEqual(7)
                    ->and(resolverFractionalDigitCount($coordinate->longitude))->toBeLessThanOrEqual(7);
            } finally {
                DB::rollBack();
            }
        });
});

/**
 * The number of fractional digits in a decimal string.
 */
function resolverFractionalDigitCount(string $decimal): int
{
    $dot = strpos($decimal, '.');

    return $dot === false ? 0 : strlen($decimal) - $dot - 1;
}

/**
 * True when $value lies in the closed range [$min, $max], compared numerically.
 */
function resolverDecimalInRange(string $value, string $min, string $max): bool
{
    return Decimal::compare($value, $min, 7) >= 0
        && Decimal::compare($value, $max, 7) <= 0;
}
