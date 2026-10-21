<?php

declare(strict_types=1);

// Feature: Waselna, Property 51: Restaurant_Status transitions are constrained and publication is conditional

/*
 * For any ordered pair of Restaurant_Statuses, the transition is permitted
 * exactly when it is one of draft->pending_review, pending_review->published,
 * pending_review->draft, published->suspended, suspended->published, any other
 * pair being rejected with invalid_status_transition leaving the
 * Restaurant_Status unchanged; a transition to published additionally requires
 * at least one active Restaurant_Branch, at least one available Menu_Item, and
 * at least one Delivery_Zone assigned to an active Restaurant_Branch, otherwise
 * it is rejected with restaurant_not_publishable naming every unmet condition;
 * a created Restaurant holds draft; and the transition-table membership is
 * decided before the actor's permission so a non-permitted pair is rejected
 * with invalid_status_transition whoever asked.
 *
 * **Validates: Requirements 18.1, 18.2, 59.1, 59.3, 59.4, 59.5, 59.6, 59.8**
 *
 * The property splits along the natural seam the design draws: the pure
 * transition table lives in the framework-free RestaurantStatus value type
 * (task 9.1) and is exercised with no infrastructure at all, while the
 * rejection ordering and the publication prerequisites live in the
 * RestaurantLifecycleServiceImplementation and are exercised against a real
 * database so the locked read, the stored status, and the three prerequisite
 * reads are the production ones.
 *
 * The transition-table conjunct is checked against an independent oracle: the
 * five permitted edges are listed by hand, as the design's lifecycle diagram
 * draws them, and canTransition must agree with set membership on that list for
 * every generated ordered pair — including self-edges and pairs touching an
 * unknown status value, which must all be non-permitted. Because the generated
 * status alphabet contains strings that are not valid Restaurant_Statuses, the
 * "unknown states are rejected" clause is reached by construction rather than
 * by chance.
 *
 * The service conjunct drives transitionStatus for every generated (from, to,
 * actor, prerequisite-availability) tuple and asserts the exact outcome the
 * requirements demand: a non-permitted edge throws invalid_status_transition
 * before any permission or prerequisite check and leaves the stored status
 * untouched (membership-before-permission ordering, Requirement 59.6); a
 * permitted edge the actor may not make throws forbidden; a permitted,
 * authorized edge to published throws restaurant_not_publishable naming exactly
 * the unmet prerequisites when any of the three is absent, and otherwise
 * commits; and every other permitted, authorized edge commits and moves the
 * stored status. Each generated tuple runs inside its own transaction rolled
 * back afterwards so inputs do not interfere.
 *
 * Time comes from a FrozenClock so the committed updated_at is deterministic
 * and no wall-clock is read (Requirement 40.7). The Zone prerequisite is
 * answered by an in-test double of the ZoneAssignmentDirectory boundary so no
 * PostGIS is needed and no cross-module table is touched. The schema is built
 * directly from the catalog columns the lifecycle reads, matching the Catalog
 * feature suite's SQLite approach. Global helper names are prefixed statusTx92
 * so they never collide with the other Catalog suites' helpers.
 */

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Domain\RestaurantStatus;
use App\Modules\Catalog\Domain\RestaurantStatusTransition;
use App\Modules\Catalog\Repositories\MenuCacheInvalidator;
use App\Modules\Catalog\Repositories\RestaurantLifecycleRepository;
use App\Modules\Catalog\Services\RestaurantLifecycleServiceImplementation;
use App\Modules\Zone\Boundary\ZoneAssignmentDirectory;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ConflictException;
use App\Shared\Support\FrozenClock;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(TestTrait::class);

/**
 * The frozen instant every committed transition stamps its updated_at from.
 */
const STATUS_TX_92_EPOCH = '2025-06-01T10:00:00Z';

/**
 * The five permitted edges, listed by hand exactly as the design's lifecycle
 * diagram draws them. The oracle for the transition-table conjunct is set
 * membership in this list; canTransition must agree with it on every pair.
 *
 * @return list<array{0: string, 1: string}>
 */
function statusTx92PermittedEdges(): array
{
    return [
        [RestaurantStatus::DRAFT, RestaurantStatus::PENDING_REVIEW],
        [RestaurantStatus::PENDING_REVIEW, RestaurantStatus::PUBLISHED],
        [RestaurantStatus::PENDING_REVIEW, RestaurantStatus::DRAFT],
        [RestaurantStatus::PUBLISHED, RestaurantStatus::SUSPENDED],
        [RestaurantStatus::SUSPENDED, RestaurantStatus::PUBLISHED],
    ];
}

/**
 * True exactly when (from, to) is one of the five permitted edges — the
 * independent oracle the service and the value type are both checked against.
 */
function statusTx92IsPermitted(string $from, string $to): bool
{
    foreach (statusTx92PermittedEdges() as [$f, $t]) {
        if ($f === $from && $t === $to) {
            return true;
        }
    }

    return false;
}

/**
 * The status alphabet the generators draw from: the four real
 * Restaurant_Statuses plus two values that are not valid statuses, so pairs
 * touching an unknown state are produced by construction and must be rejected.
 *
 * @return ErisGenerator<string>
 */
function statusTx92Alphabet(): ErisGenerator
{
    return Generators::elements([
        RestaurantStatus::DRAFT,
        RestaurantStatus::PENDING_REVIEW,
        RestaurantStatus::PUBLISHED,
        RestaurantStatus::SUSPENDED,
        'archived',   // not a Restaurant_Status
        'deleted',    // not a Restaurant_Status
    ]);
}

/**
 * The actor requesting a transition: an Administrator, the owning
 * Restaurant_Operator (restaurantId filled in at apply time), or a Customer
 * with no lifecycle permission at all. The role string alone is generated; the
 * ActorContext is built once the Restaurant id exists.
 *
 * @return ErisGenerator<string>
 */
function statusTx92ActorRole(): ErisGenerator
{
    return Generators::elements([
        'administrator',
        'restaurant_operator',
        'customer',
    ]);
}

/**
 * The three publication prerequisites as independent booleans, so every one of
 * the eight combinations — all absent, each singly present, all present — is
 * reachable and the "names every unmet condition" clause is exercised across
 * the whole lattice.
 *
 * @return ErisGenerator<array{branch: bool, item: bool, zone: bool}>
 */
function statusTx92Prerequisites(): ErisGenerator
{
    return Generators::associative([
        'branch' => Generators::elements([true, false]),
        'item' => Generators::elements([true, false]),
        'zone' => Generators::elements([true, false]),
    ]);
}

/**
 * An in-test ZoneAssignmentDirectory whose only consulted answer is whether the
 * Restaurant has a Delivery_Zone on an active Restaurant_Branch; the Catalog
 * lifecycle never calls the other methods.
 */
function statusTx92ZoneDirectory(bool $hasZoneOnActiveBranch): ZoneAssignmentDirectory
{
    return new class($hasZoneOnActiveBranch) implements ZoneAssignmentDirectory
    {
        public function __construct(private readonly bool $hasZone) {}

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
            return $this->hasZone;
        }
    };
}

/**
 * The lifecycle service wired with the real repository, the zone double, an
 * array-backed cache, a frozen clock, and a throwaway event dispatcher.
 */
function statusTx92Service(bool $hasZoneOnActiveBranch): RestaurantLifecycleServiceImplementation
{
    return new RestaurantLifecycleServiceImplementation(
        new RestaurantLifecycleRepository,
        statusTx92ZoneDirectory($hasZoneOnActiveBranch),
        new MenuCacheInvalidator(new CacheRepository(new ArrayStore)),
        FrozenClock::at(STATUS_TX_92_EPOCH),
        new Dispatcher,
    );
}

/**
 * Builds the minimal Catalog tables the lifecycle reads, from the same columns
 * the catalog migration defines, so SQLite in-memory can run them.
 */
function statusTx92CreateSchema(): void
{
    Schema::dropIfExists('menu_items');
    Schema::dropIfExists('menu_categories');
    Schema::dropIfExists('restaurant_branches');
    Schema::dropIfExists('restaurants');

    Schema::create('restaurants', function ($table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->text('description')->nullable();
        $table->string('status', 20)->default('draft');
        $table->string('currency', 3)->default('USD');
        $table->timestampTz('created_at')->nullable();
        $table->timestampTz('updated_at')->nullable();
    });

    Schema::create('restaurant_branches', function ($table): void {
        $table->string('id')->primary();
        $table->string('restaurant_id');
        $table->string('name');
        $table->boolean('is_active')->default(true);
    });

    Schema::create('menu_categories', function ($table): void {
        $table->string('id')->primary();
        $table->string('restaurant_id');
        $table->string('name');
    });

    Schema::create('menu_items', function ($table): void {
        $table->string('id')->primary();
        $table->string('restaurant_id');
        $table->string('category_id');
        $table->string('name');
        $table->integer('price_minor')->default(100);
        $table->boolean('is_available')->default(true);
        $table->timestampTz('archived_at')->nullable();
    });
}

/**
 * Seeds a Restaurant at the given status and returns its id.
 */
function statusTx92SeedRestaurant(string $status): string
{
    $id = (string) Str::ulid();
    DB::table('restaurants')->insert([
        'id' => $id,
        'name' => 'Resto '.Str::random(5),
        'status' => $status,
        'currency' => 'USD',
    ]);

    return $id;
}

function statusTx92SeedActiveBranch(string $restaurantId): void
{
    DB::table('restaurant_branches')->insert([
        'id' => (string) Str::ulid(),
        'restaurant_id' => $restaurantId,
        'name' => 'Branch',
        'is_active' => true,
    ]);
}

function statusTx92SeedAvailableItem(string $restaurantId): void
{
    $categoryId = (string) Str::ulid();
    DB::table('menu_categories')->insert([
        'id' => $categoryId, 'restaurant_id' => $restaurantId, 'name' => 'Cat',
    ]);
    DB::table('menu_items')->insert([
        'id' => (string) Str::ulid(),
        'restaurant_id' => $restaurantId,
        'category_id' => $categoryId,
        'name' => 'Item',
        'price_minor' => 500,
        'is_available' => true,
        'archived_at' => null,
    ]);
}

function statusTx92StatusOf(string $restaurantId): string
{
    /** @var string $status */
    $status = DB::table('restaurants')->where('id', $restaurantId)->value('status');

    return $status;
}

/**
 * Builds the acting Platform user for a generated role. The Restaurant_Operator
 * is made the owner of the Restaurant under test, so an operator rejection is
 * about the edge not the ownership — the cross-Restaurant ownership rejection is
 * pinned by the feature suite.
 */
function statusTx92Actor(string $role, string $restaurantId): ActorContext
{
    return match ($role) {
        'administrator' => new ActorContext((string) Str::ulid(), 'administrator', 'active'),
        'restaurant_operator' => new ActorContext(
            (string) Str::ulid(),
            'restaurant_operator',
            'active',
            restaurantId: $restaurantId,
        ),
        default => new ActorContext((string) Str::ulid(), 'customer', 'active'),
    };
}

/**
 * Whether the generated actor role may make the permitted edge (from, to),
 * mirroring the service's authorization matrix: an Administrator may make any
 * permitted edge; the owning Restaurant_Operator may only submit for review and
 * withdraw; every other actor is forbidden. Only ever asked for a permitted
 * edge.
 */
function statusTx92ActorMayMake(string $role, string $from, string $to): bool
{
    if ($role === 'administrator') {
        return true;
    }

    if ($role === 'restaurant_operator') {
        return ($from === RestaurantStatus::DRAFT && $to === RestaurantStatus::PENDING_REVIEW)
            || ($from === RestaurantStatus::PENDING_REVIEW && $to === RestaurantStatus::DRAFT);
    }

    return false;
}

it('permits exactly the five documented edges and treats every other ordered pair as non-permitted', function (): void {
    $this->limitTo(100)->forAll(
        statusTx92Alphabet(),
        statusTx92Alphabet(),
    )->then(function (string $from, string $to): void {
        $expected = statusTx92IsPermitted($from, $to);

        // The pure transition table agrees with the hand-listed oracle on every
        // pair, including self-edges and pairs touching an unknown status
        // (Requirements 59.3, 59.6).
        expect(RestaurantStatus::canTransition($from, $to))->toBe($expected);

        // A status equal to itself is never a permitted transition.
        if ($from === $to) {
            expect(RestaurantStatus::canTransition($from, $to))->toBeFalse();
        }

        // A pair touching an unknown status is never permitted.
        if (! RestaurantStatus::isValid($from) || ! RestaurantStatus::isValid($to)) {
            expect(RestaurantStatus::canTransition($from, $to))->toBeFalse();
        }

        // transitionsFrom and canTransition describe the same table.
        expect(in_array($to, RestaurantStatus::transitionsFrom($from), true))->toBe($expected);

        // Publication prerequisites are required exactly when the target is
        // published (Requirement 59.5).
        expect(RestaurantStatus::requiresPublishPrerequisites($to))
            ->toBe($to === RestaurantStatus::PUBLISHED);
    });
});

it('rejects, authorizes, and gates every transition in the fixed order, leaving a rejected status unchanged', function (): void {
    $this->limitTo(100)->forAll(
        statusTx92Alphabet(),
        statusTx92Alphabet(),
        statusTx92ActorRole(),
        statusTx92Prerequisites(),
    )->then(function (string $from, string $to, string $role, array $prereqs): void {
        // Only a real starting status can be seeded and stored; an unknown
        // "from" cannot be a stored Restaurant_Status, so the stored-start cases
        // use the four real statuses while the pure table above already covers
        // unknown "from" membership. Skipping keeps each generated input a
        // faithful service exercise.
        if (! RestaurantStatus::isValid($from)) {
            expect(RestaurantStatus::canTransition($from, $to))->toBeFalse();

            return;
        }

        DB::beginTransaction();

        try {
            statusTx92CreateSchema();

            $service = statusTx92Service(hasZoneOnActiveBranch: $prereqs['zone']);
            $restaurantId = statusTx92SeedRestaurant($from);

            if ($prereqs['branch']) {
                statusTx92SeedActiveBranch($restaurantId);
            }
            if ($prereqs['item']) {
                statusTx92SeedAvailableItem($restaurantId);
            }

            $actor = statusTx92Actor($role, $restaurantId);
            $permitted = statusTx92IsPermitted($from, $to);
            $mayMake = $permitted && statusTx92ActorMayMake($role, $from, $to);

            $call = fn () => $service->transitionStatus(
                new RestaurantStatusTransition($actor, $restaurantId, $to),
            );

            if (! $permitted) {
                // Membership before permission: a non-permitted edge is rejected
                // with invalid_status_transition whoever asked, and the stored
                // status is untouched (Requirements 59.3, 59.6).
                try {
                    $call();
                    throw new RuntimeException('Expected invalid_status_transition.');
                } catch (ConflictException $e) {
                    expect($e->errorCode())->toBe('invalid_status_transition');
                }
                expect(statusTx92StatusOf($restaurantId))->toBe($from);

                return;
            }

            if (! $mayMake) {
                // A permitted edge the actor may not make is forbidden, and the
                // stored status is untouched (Requirement 59.4).
                expect($call)->toThrow(AuthorizationException::class);
                expect(statusTx92StatusOf($restaurantId))->toBe($from);

                return;
            }

            if (RestaurantStatus::requiresPublishPrerequisites($to)) {
                $allPrereqs = $prereqs['branch'] && $prereqs['item'] && $prereqs['zone'];

                if (! $allPrereqs) {
                    // A publication missing any prerequisite is rejected with
                    // restaurant_not_publishable naming exactly the unmet
                    // conditions, status untouched (Requirements 59.5).
                    try {
                        $call();
                        throw new RuntimeException('Expected restaurant_not_publishable.');
                    } catch (ConflictException $e) {
                        expect($e->errorCode())->toBe('restaurant_not_publishable');

                        /** @var string $unmet */
                        $unmet = $e->context()['unmet_conditions'] ?? '';
                        $names = $unmet === '' ? [] : explode(',', $unmet);

                        $expectedUnmet = [];
                        if (! $prereqs['branch']) {
                            $expectedUnmet[] = 'no_active_branch';
                        }
                        if (! $prereqs['item']) {
                            $expectedUnmet[] = 'no_available_menu_item';
                        }
                        if (! $prereqs['zone']) {
                            $expectedUnmet[] = 'no_zone_on_active_branch';
                        }

                        sort($names);
                        sort($expectedUnmet);
                        expect($names)->toEqual($expectedUnmet);
                    }
                    expect(statusTx92StatusOf($restaurantId))->toBe($from);

                    return;
                }
            }

            // A permitted, authorized edge whose prerequisites (if any) all hold
            // commits and moves the stored status (Requirements 59.3, 59.4, 59.5).
            $view = $call();
            expect($view->status)->toBe($to);
            expect(statusTx92StatusOf($restaurantId))->toBe($to);
        } finally {
            DB::rollBack();
        }
    });
});
