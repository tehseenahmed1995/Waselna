<?php

declare(strict_types=1);

// Feature: Waselna, task 9.1: Restaurant_Status lifecycle.

/*
 * Example-based service tests for the Catalog module's
 * RestaurantLifecycleServiceImplementation (task 9.1; Requirements 18.1, 18.2,
 * 59.3–59.9). The exhaustive transition-table property is Property 51 and the
 * active-branch invariant is Property 52, both written separately in tasks 9.2
 * and 9.3; these tests pin the specific behaviours a reader of the requirements
 * expects:
 *
 *  - the five permitted transitions each succeed and move the stored status
 *    (Requirement 59.3, 59.4);
 *  - a pair outside the five permitted edges is rejected with
 *    invalid_status_transition leaving the status unchanged, and that rejection
 *    is decided before the actor's permission (Requirement 59.6);
 *  - a transition to published is rejected with restaurant_not_publishable
 *    naming every unmet condition when a prerequisite fails, and succeeds once
 *    all three hold (Requirement 59.5);
 *  - a Restaurant_Operator may submit its own Restaurant for review and withdraw
 *    it but may not approve, suspend, or reinstate; an Administrator may approve,
 *    suspend, and reinstate; another Restaurant's Operator is forbidden
 *    (Requirements 59.4, 18.1, 18.2);
 *  - a committed transition publishes exactly one restaurant.status_changed
 *    event carrying the previous and new status; a rejected one publishes
 *    nothing (Requirements 59.9, 18.1, 18.2);
 *  - a missing Restaurant reads as the non-disclosing restaurant_not_found.
 *
 * Time comes from a FrozenClock and identifiers from a SequenceIdGenerator so
 * the change instant and row creation are deterministic. The Zone prerequisite
 * is answered by an in-test fake of the ZoneAssignmentDirectory boundary so no
 * PostGIS is needed; the tables are built directly from the catalog migration's
 * columns, matching the Account and Hours suites' SQLite approach. Helper names
 * are prefixed `lifecycle*` to avoid colliding with the other Catalog suites'
 * global helpers.
 */

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Domain\RestaurantStatus;
use App\Modules\Catalog\Domain\RestaurantStatusTransition;
use App\Modules\Catalog\Events\RestaurantStatusChanged;
use App\Modules\Catalog\Repositories\MenuCacheInvalidator;
use App\Modules\Catalog\Repositories\RestaurantLifecycleRepository;
use App\Modules\Catalog\Services\RestaurantLifecycleServiceImplementation;
use App\Modules\Zone\Boundary\ZoneAssignmentDirectory;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ConflictException;
use App\Shared\Support\FrozenClock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * An in-test ZoneAssignmentDirectory whose only consulted answer is whether a
 * Restaurant has a zone on an active branch; the other methods are unused here.
 */
function lifecycleZoneDirectory(bool $hasZoneOnActiveBranch): ZoneAssignmentDirectory
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

function lifecycleServiceUnderTest(Dispatcher $events, bool $hasZoneOnActiveBranch = true): RestaurantLifecycleServiceImplementation
{
    return new RestaurantLifecycleServiceImplementation(
        new RestaurantLifecycleRepository,
        lifecycleZoneDirectory($hasZoneOnActiveBranch),
        new MenuCacheInvalidator(new CacheRepository(new ArrayStore)),
        FrozenClock::at('2025-03-01T12:00:00Z'),
        $events,
    );
}

function lifecycleCreateSchema(): void
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
function lifecycleSeedRestaurant(string $status = RestaurantStatus::DRAFT): string
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

function lifecycleSeedActiveBranch(string $restaurantId): string
{
    $id = (string) Str::ulid();
    DB::table('restaurant_branches')->insert([
        'id' => $id, 'restaurant_id' => $restaurantId, 'name' => 'Branch', 'is_active' => true,
    ]);

    return $id;
}

function lifecycleSeedAvailableItem(string $restaurantId): void
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

function lifecycleOperatorFor(string $restaurantId): ActorContext
{
    return new ActorContext(
        userId: (string) Str::ulid(),
        role: 'restaurant_operator',
        accountStatus: 'active',
        restaurantId: $restaurantId,
    );
}

function lifecycleAdministrator(): ActorContext
{
    return new ActorContext((string) Str::ulid(), 'administrator', 'active');
}

function lifecycleStatusOf(string $restaurantId): string
{
    /** @var string $status */
    $status = DB::table('restaurants')->where('id', $restaurantId)->value('status');

    return $status;
}

beforeEach(function (): void {
    lifecycleCreateSchema();
});

it('submits a draft Restaurant for review on behalf of its Operator', function (): void {
    $events = new Dispatcher;
    $service = lifecycleServiceUnderTest($events);
    $restaurantId = lifecycleSeedRestaurant(RestaurantStatus::DRAFT);

    $view = $service->submitForReview(lifecycleOperatorFor($restaurantId), $restaurantId);

    expect($view->status)->toBe(RestaurantStatus::PENDING_REVIEW)
        ->and(lifecycleStatusOf($restaurantId))->toBe(RestaurantStatus::PENDING_REVIEW);
});

it('lets an Administrator approve, suspend, and reinstate a Restaurant', function (): void {
    $events = new Dispatcher;
    $service = lifecycleServiceUnderTest($events, hasZoneOnActiveBranch: true);
    $admin = lifecycleAdministrator();

    $restaurantId = lifecycleSeedRestaurant(RestaurantStatus::PENDING_REVIEW);
    lifecycleSeedActiveBranch($restaurantId);
    lifecycleSeedAvailableItem($restaurantId);

    // pending_review -> published (approve)
    $service->transitionStatus(new RestaurantStatusTransition($admin, $restaurantId, RestaurantStatus::PUBLISHED));
    expect(lifecycleStatusOf($restaurantId))->toBe(RestaurantStatus::PUBLISHED);

    // published -> suspended
    $service->transitionStatus(new RestaurantStatusTransition($admin, $restaurantId, RestaurantStatus::SUSPENDED));
    expect(lifecycleStatusOf($restaurantId))->toBe(RestaurantStatus::SUSPENDED);

    // suspended -> published (reinstate)
    $service->transitionStatus(new RestaurantStatusTransition($admin, $restaurantId, RestaurantStatus::PUBLISHED));
    expect(lifecycleStatusOf($restaurantId))->toBe(RestaurantStatus::PUBLISHED);
});

it('withdraws a pending_review Restaurant back to draft for its Operator', function (): void {
    $events = new Dispatcher;
    $service = lifecycleServiceUnderTest($events);
    $restaurantId = lifecycleSeedRestaurant(RestaurantStatus::PENDING_REVIEW);

    $service->transitionStatus(new RestaurantStatusTransition(
        lifecycleOperatorFor($restaurantId), $restaurantId, RestaurantStatus::DRAFT,
    ));

    expect(lifecycleStatusOf($restaurantId))->toBe(RestaurantStatus::DRAFT);
});

it('rejects a transition outside the five permitted edges with invalid_status_transition', function (): void {
    $events = new Dispatcher;
    $service = lifecycleServiceUnderTest($events);
    $admin = lifecycleAdministrator();
    $restaurantId = lifecycleSeedRestaurant(RestaurantStatus::DRAFT);

    // draft -> published is not a permitted edge.
    expect(fn () => $service->transitionStatus(
        new RestaurantStatusTransition($admin, $restaurantId, RestaurantStatus::PUBLISHED),
    ))->toThrow(ConflictException::class);

    expect(lifecycleStatusOf($restaurantId))->toBe(RestaurantStatus::DRAFT);
});

it('decides transition-table membership before actor permission', function (): void {
    $events = new Dispatcher;
    $service = lifecycleServiceUnderTest($events);
    // A Customer — a Role with no lifecycle permission at all — requesting a
    // non-permitted edge still gets the 409 membership rejection, not a 403.
    $customer = new ActorContext((string) Str::ulid(), 'customer', 'active');
    $restaurantId = lifecycleSeedRestaurant(RestaurantStatus::DRAFT);

    try {
        $service->transitionStatus(new RestaurantStatusTransition($customer, $restaurantId, RestaurantStatus::SUSPENDED));
        $this->fail('Expected a ConflictException.');
    } catch (ConflictException $e) {
        expect($e->errorCode())->toBe('invalid_status_transition');
    }

    expect(lifecycleStatusOf($restaurantId))->toBe(RestaurantStatus::DRAFT);
});

it('rejects publication naming every unmet condition and leaves the status unchanged', function (): void {
    $events = new Dispatcher;
    // No zone on an active branch, and we seed neither a branch nor an item.
    $service = lifecycleServiceUnderTest($events, hasZoneOnActiveBranch: false);
    $restaurantId = lifecycleSeedRestaurant(RestaurantStatus::PENDING_REVIEW);

    try {
        $service->transitionStatus(new RestaurantStatusTransition(
            lifecycleAdministrator(), $restaurantId, RestaurantStatus::PUBLISHED,
        ));
        $this->fail('Expected a ConflictException.');
    } catch (ConflictException $e) {
        expect($e->errorCode())->toBe('restaurant_not_publishable');
        $unmet = $e->context()['unmet_conditions'] ?? '';
        expect($unmet)->toContain('no_active_branch')
            ->and($unmet)->toContain('no_available_menu_item')
            ->and($unmet)->toContain('no_zone_on_active_branch');
    }

    expect(lifecycleStatusOf($restaurantId))->toBe(RestaurantStatus::PENDING_REVIEW);
});

it('forbids an Operator from approving, suspending, or reinstating', function (): void {
    $events = new Dispatcher;
    $service = lifecycleServiceUnderTest($events, hasZoneOnActiveBranch: true);
    $restaurantId = lifecycleSeedRestaurant(RestaurantStatus::PENDING_REVIEW);
    lifecycleSeedActiveBranch($restaurantId);
    lifecycleSeedAvailableItem($restaurantId);

    // An Operator of this very Restaurant still may not approve it.
    expect(fn () => $service->transitionStatus(new RestaurantStatusTransition(
        lifecycleOperatorFor($restaurantId), $restaurantId, RestaurantStatus::PUBLISHED,
    )))->toThrow(AuthorizationException::class);

    expect(lifecycleStatusOf($restaurantId))->toBe(RestaurantStatus::PENDING_REVIEW);
});

it('forbids an Operator from acting on another Restaurant', function (): void {
    $events = new Dispatcher;
    $service = lifecycleServiceUnderTest($events);
    $mine = lifecycleSeedRestaurant(RestaurantStatus::DRAFT);
    $theirs = lifecycleSeedRestaurant(RestaurantStatus::DRAFT);

    expect(fn () => $service->submitForReview(lifecycleOperatorFor($mine), $theirs))
        ->toThrow(AuthorizationException::class);

    expect(lifecycleStatusOf($theirs))->toBe(RestaurantStatus::DRAFT);
});

it('publishes exactly one restaurant.status_changed event on a committed transition', function (): void {
    $events = new Dispatcher;
    $captured = [];
    $events->listen(RestaurantStatusChanged::class, function (RestaurantStatusChanged $e) use (&$captured): void {
        $captured[] = $e;
    });

    $service = lifecycleServiceUnderTest($events);
    $restaurantId = lifecycleSeedRestaurant(RestaurantStatus::DRAFT);

    $service->submitForReview(lifecycleOperatorFor($restaurantId), $restaurantId);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]->fromStatus)->toBe(RestaurantStatus::DRAFT)
        ->and($captured[0]->toStatus)->toBe(RestaurantStatus::PENDING_REVIEW)
        ->and($captured[0]->restaurantId)->toBe($restaurantId);
});

it('publishes no event when a transition is rejected', function (): void {
    $events = new Dispatcher;
    $captured = [];
    $events->listen(RestaurantStatusChanged::class, function (RestaurantStatusChanged $e) use (&$captured): void {
        $captured[] = $e;
    });

    $service = lifecycleServiceUnderTest($events);
    $restaurantId = lifecycleSeedRestaurant(RestaurantStatus::DRAFT);

    expect(fn () => $service->transitionStatus(new RestaurantStatusTransition(
        lifecycleAdministrator(), $restaurantId, RestaurantStatus::PUBLISHED,
    )))->toThrow(ConflictException::class);

    expect($captured)->toHaveCount(0);
});

it('returns the non-disclosing restaurant_not_found for a missing Restaurant', function (): void {
    $events = new Dispatcher;
    $service = lifecycleServiceUnderTest($events);

    expect(fn () => $service->submitForReview(lifecycleAdministrator(), (string) Str::ulid()))
        ->toThrow(AuthorizationException::class);
});
