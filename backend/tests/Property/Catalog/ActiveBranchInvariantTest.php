<?php

declare(strict_types=1);

// Feature: waselna, Property 52: A published Restaurant always retains an active Restaurant_Branch

/*
 * For any sequence of Restaurant_Branch activation changes on a Restaurant whose
 * Restaurant_Status is `published`, deactivating the last active
 * Restaurant_Branch is rejected with `last_active_branch` leaving that
 * Restaurant_Branch unchanged, so at least one active Restaurant_Branch exists
 * at every observable point.
 *
 * **Validates: Requirements 32.4, 32.3**
 *
 * The subject is the Catalog module's RestaurantManagementServiceImplementation
 * (task 7.6) driven through its RestaurantManagementService seam — specifically
 * the active-flag write of setBranchActive() — together with the
 * Restaurant_Status active-branch guard of task 9.1. The property drives a
 * generated sequence of activation and deactivation requests against the
 * Restaurant_Branches of one Restaurant and asserts the single invariant
 * Requirement 32.4 fixes: a `published` Restaurant never reaches zero active
 * Restaurant_Branches.
 *
 * Each generated input is a fixed-length initial active/inactive pattern for
 * 1..5 Restaurant_Branches (always at least one active, since a published
 * Restaurant always has one) and a fixed-length sequence of 1..12
 * (branchIndex, desiredActive) operations. The branch index is taken modulo the
 * branch count so it always addresses an existing Restaurant_Branch. Both
 * dimensions use fixed-size vector generators so generation and shrinking stay
 * bounded. The property applies the sequence operation by operation and, after
 * each, checks:
 *
 *  - *Invariant holds.* At least one active Restaurant_Branch exists at every
 *    observable point between operations (Requirement 32.4).
 *  - *The last-active deactivation is refused with the documented code.* A
 *    request to deactivate the only remaining active Restaurant_Branch is
 *    rejected with a ConflictException carrying `last_active_branch`, and that
 *    Restaurant_Branch's stored active flag is left unchanged (Requirement
 *    32.4). Any other operation — activating a branch, or deactivating one that
 *    is not the last active — is accepted and the stored flag reflects the
 *    request (Requirement 32.3).
 *
 * The sibling case anchors the scope of the guard: on a Restaurant that is NOT
 * `published` (draft, pending_review, or suspended), deactivating every
 * Restaurant_Branch is accepted and the active count may legitimately reach
 * zero, so the `last_active_branch` guard fires only in the `published` state
 * (Requirement 32.4).
 *
 * Database and determinism. The property runs on the suite's SQLite in-memory
 * connection (phpunit.xml forces DB_CONNECTION=sqlite and DB_DATABASE=:memory:;
 * a CLI run against the pgsql container must set those explicitly because the
 * container .env defaults to pgsql). The `restaurants` and `restaurant_branches`
 * tables are built directly from the task 7.1 migration's columns — matching the
 * Catalog Feature and Property suites' SQLite approach — rather than by running
 * the PostGIS/btree_gist-bearing migration set, which SQLite cannot apply. Each
 * generated input runs inside a transaction rolled back afterwards, as the
 * Testing Strategy prescribes for database-touching properties. Identifiers come
 * only from the injected SequenceIdGenerator and Str::ulid, so row creation is
 * deterministic; the subject reads time only through the injected FrozenClock,
 * never the wall clock.
 *
 * All global helpers are prefixed `activeBranch93` so they never collide with
 * the same-named helpers of the Catalog Feature or other Property tests loaded
 * in the same run.
 */

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Contracts\Services\RestaurantManagementService;
use App\Modules\Catalog\Repositories\RestaurantManagementRepository;
use App\Modules\Catalog\Services\RestaurantManagementServiceImplementation;
use App\Modules\Zone\Boundary\ZoneAssignmentDirectory;
use App\Modules\Zone\Contracts\Services\AddressResolver;
use App\Shared\Domain\Coordinate;
use App\Shared\Domain\Exceptions\ConflictException;
use App\Shared\Domain\PostalAddress;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\SequenceIdGenerator;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(TestTrait::class);

/**
 * A fixed UTC instant for the injected Clock, so every `updated_at` write the
 * subject performs is deterministic and no wall clock is read.
 */
const ACTIVE_BRANCH93_NOW = '2024-06-01T12:00:00+00:00';

/**
 * Builds the two tables the active-flag write touches directly from the task 7.1
 * migration's columns: `restaurants` (carrying the `status` the guard reads) and
 * `restaurant_branches` (carrying the `is_active` flag). The Postgres-only
 * CHECK, trigram, and GIST constraints are omitted; this property is about the
 * service's own guard, which decides in PHP, so the portable columns are enough
 * to observe it on SQLite.
 */
function activeBranch93CreateSchema(): void
{
    Schema::dropIfExists('restaurant_branches');
    Schema::dropIfExists('restaurants');

    Schema::create('restaurants', function ($table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('status', 20)->default('draft');
        $table->string('currency', 3)->default('USD');
        $table->integer('tax_rate_bp')->default(0);
        $table->integer('base_delivery_fee')->default(0);
        $table->string('time_zone', 64)->default('UTC');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    Schema::create('restaurant_branches', function ($table): void {
        $table->string('id')->primary();
        $table->string('restaurant_id');
        $table->string('name');
        $table->string('address')->nullable();
        $table->float('latitude')->nullable();
        $table->float('longitude')->nullable();
        $table->string('time_zone', 64)->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
}

/**
 * A Zone boundary double that reports no assigned Delivery_Zones. The active-flag
 * write reads only {@see ZoneAssignmentDirectory::zoneIdsForBranch()} for the
 * returned projection and never writes through the boundary, so an empty answer
 * is faithful and keeps the property free of a Zone table.
 */
function activeBranch93Zones(): ZoneAssignmentDirectory
{
    return new class implements ZoneAssignmentDirectory
    {
        public function zoneIdsForRestaurant(string $restaurantId): array
        {
            return [];
        }

        public function zoneIdsForBranch(string $branchId): array
        {
            return [];
        }

        public function replaceBranchAssignments(string $branchId, array $zoneIds): void {}

        public function anyZoneOnActiveBranch(string $restaurantId): bool
        {
            return false;
        }
    };
}

/**
 * An AddressResolver double that is never called: the active-flag write resolves
 * no address. It satisfies the constructor dependency without affecting the
 * behaviour under test.
 */
function activeBranch93Resolver(): AddressResolver
{
    return new class implements AddressResolver
    {
        public function resolve(PostalAddress $address): Coordinate
        {
            throw new \LogicException('The active-branch invariant property resolves no address.');
        }
    };
}

function activeBranch93Service(): RestaurantManagementService
{
    return new RestaurantManagementServiceImplementation(
        new RestaurantManagementRepository(new SequenceIdGenerator),
        activeBranch93Resolver(),
        activeBranch93Zones(),
        FrozenClock::at(ACTIVE_BRANCH93_NOW),
    );
}

/**
 * An Administrator actor, which {@see RestaurantManagementService} authorises
 * over any Restaurant, so the property exercises the guard rather than the
 * ownership rule.
 */
function activeBranch93Administrator(): ActorContext
{
    return new ActorContext((string) Str::ulid(), 'administrator', 'active');
}

/**
 * Seeds a Restaurant with the given status and returns its identifier.
 */
function activeBranch93SeedRestaurant(string $status): string
{
    $id = (string) Str::ulid();
    DB::table('restaurants')->insert([
        'id' => $id,
        'name' => 'Resto '.Str::random(5),
        'status' => $status,
        'currency' => 'USD',
        'tax_rate_bp' => 0,
        'base_delivery_fee' => 0,
        'time_zone' => 'UTC',
        'created_at' => ACTIVE_BRANCH93_NOW,
        'updated_at' => ACTIVE_BRANCH93_NOW,
    ]);

    return $id;
}

/**
 * Seeds one Restaurant_Branch with the given active flag and returns its
 * identifier.
 */
function activeBranch93SeedBranch(string $restaurantId, bool $isActive): string
{
    $id = (string) Str::ulid();
    DB::table('restaurant_branches')->insert([
        'id' => $id,
        'restaurant_id' => $restaurantId,
        'name' => 'Branch '.Str::random(4),
        'address' => '1 Example St',
        'latitude' => 0.0,
        'longitude' => 0.0,
        'time_zone' => 'UTC',
        'is_active' => $isActive,
        'created_at' => ACTIVE_BRANCH93_NOW,
        'updated_at' => ACTIVE_BRANCH93_NOW,
    ]);

    return $id;
}

/**
 * The current number of active Restaurant_Branches of a Restaurant, read
 * straight from the table so it is independent of the service.
 */
function activeBranch93ActiveCount(string $restaurantId): int
{
    return DB::table('restaurant_branches')
        ->where('restaurant_id', $restaurantId)
        ->where('is_active', true)
        ->count();
}

/**
 * The stored active flag of a single Restaurant_Branch.
 */
function activeBranch93IsActive(string $branchId): bool
{
    return (bool) DB::table('restaurant_branches')->where('id', $branchId)->value('is_active');
}

/**
 * A fixed-length initial active/inactive pattern for 1..5 Restaurant_Branches
 * that always holds at least one active branch, matching the invariant a
 * `published` Restaurant starts in. A fixed-size vector keeps generation and
 * shrinking bounded.
 *
 * @return ErisGenerator<list<bool>>
 */
function activeBranch93InitialPattern(): ErisGenerator
{
    return Generators::bind(
        Generators::choose(1, 5),
        static fn (int $size): ErisGenerator => Generators::map(
            static function (array $flags): array {
                // Guarantee at least one active branch to honour the starting
                // state of a published Restaurant.
                if (! in_array(true, $flags, true)) {
                    $flags[0] = true;
                }

                return array_values($flags);
            },
            Generators::vector($size, Generators::bool())
        )
    );
}

/**
 * A fixed-length sequence of 1..12 operations, each a (branchIndex,
 * desiredActive) pair. The branch index is a non-negative integer later taken
 * modulo the branch count so it always addresses an existing Restaurant_Branch.
 * A fixed-size vector keeps generation and shrinking bounded.
 *
 * @return ErisGenerator<list<array{int, bool}>>
 */
function activeBranch93Operations(): ErisGenerator
{
    return Generators::bind(
        Generators::choose(1, 12),
        static fn (int $size): ErisGenerator => Generators::vector(
            $size,
            Generators::tuple(
                Generators::choose(0, 100),
                Generators::bool(),
            )
        )
    );
}

beforeEach(function (): void {
    activeBranch93CreateSchema();
});

it('never lets a published Restaurant reach zero active Restaurant_Branches', function (): void {
    $this->limitTo(100)->forAll(
        activeBranch93InitialPattern(),
        activeBranch93Operations(),
    )->then(function (array $pattern, array $operations): void {
        DB::beginTransaction();

        try {
            $service = activeBranch93Service();
            $actor = activeBranch93Administrator();

            $restaurantId = activeBranch93SeedRestaurant('published');

            $branchIds = [];
            foreach ($pattern as $isActive) {
                $branchIds[] = activeBranch93SeedBranch($restaurantId, (bool) $isActive);
            }
            $count = count($branchIds);

            // The invariant holds before any operation.
            expect(activeBranch93ActiveCount($restaurantId))->toBeGreaterThanOrEqual(1);

            foreach ($operations as [$rawIndex, $desiredActive]) {
                $branchId = $branchIds[$rawIndex % $count];

                $wasActive = activeBranch93IsActive($branchId);
                $activeBefore = activeBranch93ActiveCount($restaurantId);

                // A deactivation of the only remaining active Restaurant_Branch
                // is the forbidden move (Requirement 32.4).
                $wouldEmptyLastActive = ! $desiredActive && $wasActive && $activeBefore === 1;

                if ($wouldEmptyLastActive) {
                    $rejected = false;

                    try {
                        $service->setBranchActive($actor, $restaurantId, $branchId, false);
                    } catch (ConflictException $exception) {
                        $rejected = true;
                        expect($exception->errorCode())->toBe('last_active_branch');
                    }

                    expect($rejected)->toBeTrue(
                        'Deactivating the last active Restaurant_Branch of a published Restaurant must be rejected with last_active_branch.'
                    );

                    // The refused Restaurant_Branch is left unchanged.
                    expect(activeBranch93IsActive($branchId))->toBeTrue();
                } else {
                    $view = $service->setBranchActive($actor, $restaurantId, $branchId, $desiredActive);

                    // An accepted request persists exactly the submitted flag
                    // (Requirement 32.3).
                    expect($view->isActive)->toBe($desiredActive);
                    expect(activeBranch93IsActive($branchId))->toBe($desiredActive);
                }

                // The invariant holds at every observable point (Requirement 32.4).
                expect(activeBranch93ActiveCount($restaurantId))->toBeGreaterThanOrEqual(1);
            }
        } finally {
            DB::rollBack();
        }
    });
});

it('lets a non-published Restaurant deactivate its last active Restaurant_Branch', function (): void {
    $this->limitTo(100)->forAll(
        Generators::elements(['draft', 'pending_review', 'suspended']),
    )->then(function (string $status): void {
        DB::beginTransaction();

        try {
            $service = activeBranch93Service();
            $actor = activeBranch93Administrator();

            $restaurantId = activeBranch93SeedRestaurant($status);
            $branchId = activeBranch93SeedBranch($restaurantId, true);

            // The guard is scoped to the `published` state: a Restaurant in any
            // other state may deactivate its sole Restaurant_Branch and reach
            // zero active branches (Requirement 32.4).
            $view = $service->setBranchActive($actor, $restaurantId, $branchId, false);

            expect($view->isActive)->toBeFalse();
            expect(activeBranch93IsActive($branchId))->toBeFalse();
            expect(activeBranch93ActiveCount($restaurantId))->toBe(0);
        } finally {
            DB::rollBack();
        }
    });
});
