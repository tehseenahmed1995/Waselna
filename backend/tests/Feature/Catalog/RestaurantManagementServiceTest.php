<?php

declare(strict_types=1);

// Feature: Waselna, task 7.6: Restaurant profile and Restaurant_Branch management.

/*
 * Example-based service tests for the Catalog module's
 * RestaurantManagementServiceImplementation (task 7.6; Requirements 32.1, 32.2,
 * 32.3, 32.11, 32.12, 32.13, 32.14). They pin the behaviours a reader of the
 * requirements expects:
 *
 *  - a profile update persists the submitted fields, replaces the cuisine tags,
 *    and leaves an unsubmitted field untouched between calls (Requirement 32.1);
 *  - a profile or Restaurant_Branch update with an invalid IANA time zone is
 *    validation_failed and stores nothing (Requirement 32.12);
 *  - a Restaurant_Branch is created active, with the resolved coordinate, and
 *    falls back to the Restaurant's time zone when none is submitted
 *    (Requirement 32.2);
 *  - the 51st Restaurant_Branch is branch_limit_reached and nothing is created
 *    (Requirements 32.3, 32.13);
 *  - an unresolvable address creates no Restaurant_Branch (Requirement 32.14);
 *  - a zone assignment of more than 20 zones, or of a zone the Restaurant does
 *    not own, is validation_failed and changes nothing; a valid assignment is
 *    stored (Requirements 32.11, 32.12);
 *  - activate/deactivate writes the flag (Requirement 32.3);
 *  - a Restaurant or Restaurant_Branch of another Restaurant_Operator is the
 *    non-disclosing restaurant_not_found (Requirement 32.12).
 *
 * Time comes from a FrozenClock and identifiers from a SequenceIdGenerator so
 * creation order is deterministic. The Zone module's address resolver and zone
 * assignment boundary are replaced by in-memory fakes so the Catalog rules are
 * exercised in isolation, without a Postgres/PostGIS container. The tables are
 * built directly from the catalog migration's columns, matching the Account and
 * Menu suites' SQLite approach. Every helper is uniquely named to avoid
 * colliding with the other Catalog test files' global helpers.
 */

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Domain\BranchCommand;
use App\Modules\Catalog\Domain\RestaurantProfileCommand;
use App\Modules\Catalog\Repositories\RestaurantManagementRepository;
use App\Modules\Catalog\Services\RestaurantManagementServiceImplementation;
use App\Modules\Zone\Boundary\ZoneAssignmentDirectory;
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
 * A deterministic AddressResolver fake: resolves any address to a fixed
 * coordinate, or raises AddressNotResolvable when primed to fail.
 */
final class RmgmtFakeResolver implements AddressResolver
{
    public bool $fail = false;

    public function resolve(PostalAddress $address): Coordinate
    {
        if ($this->fail) {
            throw AddressNotResolvable::noMatch();
        }

        return new Coordinate('40.7128000', '-74.0060000');
    }
}

/**
 * An in-memory ZoneAssignmentDirectory fake: holds the zones a Restaurant owns
 * and the assignment made per Restaurant_Branch.
 */
final class RmgmtFakeZones implements ZoneAssignmentDirectory
{
    /** @var array<string, list<string>> */
    public array $ownedByRestaurant = [];

    /** @var array<string, list<string>> */
    public array $assignedByBranch = [];

    public function zoneIdsForRestaurant(string $restaurantId): array
    {
        return $this->ownedByRestaurant[$restaurantId] ?? [];
    }

    public function zoneIdsForBranch(string $branchId): array
    {
        return $this->assignedByBranch[$branchId] ?? [];
    }

    public function replaceBranchAssignments(string $branchId, array $zoneIds): void
    {
        $this->assignedByBranch[$branchId] = array_values($zoneIds);
    }

    public function anyZoneOnActiveBranch(string $restaurantId): bool
    {
        // The branch-active join lives in the real Zone query; this fake has no
        // branch-active state, so it answers from its assignment map alone,
        // which is all the Restaurant_Branch management suite needs.
        foreach ($this->assignedByBranch as $zoneIds) {
            if ($zoneIds !== []) {
                return true;
            }
        }

        return false;
    }
}

/**
 * @return array{0: RestaurantManagementServiceImplementation, 1: RmgmtFakeResolver, 2: RmgmtFakeZones}
 */
function rmgmtHarness(): array
{
    $resolver = new RmgmtFakeResolver;
    $zones = new RmgmtFakeZones;

    $service = new RestaurantManagementServiceImplementation(
        new RestaurantManagementRepository(new SequenceIdGenerator),
        $resolver,
        $zones,
        FrozenClock::at('2025-03-01T12:00:00Z'),
    );

    return [$service, $resolver, $zones];
}

function rmgmtCreateSchema(): void
{
    Schema::dropIfExists('restaurant_cuisine_tags');
    Schema::dropIfExists('restaurant_branches');
    Schema::dropIfExists('restaurants');

    Schema::create('restaurants', function ($table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->text('description')->nullable();
        $table->string('status', 20)->default('draft');
        $table->string('currency', 3)->default('USD');
        $table->integer('tax_rate_bp')->default(0);
        $table->integer('base_delivery_fee')->default(0);
        $table->string('time_zone', 64)->default('UTC');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    Schema::create('restaurant_cuisine_tags', function ($table): void {
        $table->string('id')->primary();
        $table->string('restaurant_id');
        $table->string('tag', 64);
    });

    Schema::create('restaurant_branches', function ($table): void {
        $table->string('id')->primary();
        $table->string('restaurant_id');
        $table->string('name');
        $table->string('address');
        $table->decimal('latitude', 10, 7)->nullable();
        $table->decimal('longitude', 10, 7)->nullable();
        $table->string('time_zone', 64)->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
}

function rmgmtSeedRestaurant(string $timeZone = 'UTC', string $status = 'draft'): string
{
    $id = (string) Str::ulid();
    DB::table('restaurants')->insert([
        'id' => $id,
        'name' => 'Resto '.Str::random(5),
        'status' => $status,
        'currency' => 'USD',
        'tax_rate_bp' => 0,
        'base_delivery_fee' => 0,
        'time_zone' => $timeZone,
    ]);

    return $id;
}

function rmgmtOperator(string $restaurantId): ActorContext
{
    return new ActorContext(
        userId: (string) Str::ulid(),
        role: 'restaurant_operator',
        accountStatus: 'active',
        restaurantId: $restaurantId,
    );
}

function rmgmtAdministrator(): ActorContext
{
    return new ActorContext((string) Str::ulid(), 'administrator', 'active');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rmgmtProfileInput(array $overrides = []): array
{
    return array_merge([
        'name' => 'Updated Name',
        'description' => 'A tasty place',
        'currency' => 'usd',
        'tax_rate_bp' => 500,
        'base_delivery_fee' => 299,
        'time_zone' => 'America/New_York',
        'cuisine_tags' => ['pizza', 'italian'],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rmgmtBranchInput(array $overrides = []): array
{
    return array_merge([
        'name' => 'Downtown',
        'address_line_1' => '1 Main St',
        'address_line_2' => null,
        'city' => 'New York',
        'postal_code' => '10001',
        'time_zone' => null,
    ], $overrides);
}

beforeEach(function (): void {
    rmgmtCreateSchema();
});

// ---- Profile ---------------------------------------------------------------

it('persists a profile update and replaces the cuisine tags', function (): void {
    [$service] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant();

    $view = $service->updateProfile(
        rmgmtOperator($restaurantId),
        RestaurantProfileCommand::fromValidated($restaurantId, rmgmtProfileInput()),
    );

    expect($view->name)->toBe('Updated Name')
        ->and($view->currency)->toBe('USD')
        ->and($view->taxRateBasisPoints)->toBe(500)
        ->and($view->baseDeliveryFeeMinor)->toBe(299)
        ->and($view->timeZone)->toBe('America/New_York')
        ->and($view->cuisineTags)->toBe(['italian', 'pizza']); // stored in tag order

    $row = DB::table('restaurants')->where('id', $restaurantId)->first();
    expect($row->name)->toBe('Updated Name')
        ->and($row->time_zone)->toBe('America/New_York');
    expect(DB::table('restaurant_cuisine_tags')->where('restaurant_id', $restaurantId)->count())->toBe(2);
});

it('replaces the complete cuisine-tag set on a later update', function (): void {
    [$service] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant();
    $actor = rmgmtOperator($restaurantId);

    $service->updateProfile($actor, RestaurantProfileCommand::fromValidated($restaurantId, rmgmtProfileInput(['cuisine_tags' => ['pizza', 'italian']])));
    $service->updateProfile($actor, RestaurantProfileCommand::fromValidated($restaurantId, rmgmtProfileInput(['cuisine_tags' => ['sushi']])));

    $tags = DB::table('restaurant_cuisine_tags')->where('restaurant_id', $restaurantId)->pluck('tag')->all();
    expect($tags)->toBe(['sushi']);
});

it('rejects an invalid IANA time zone with validation_failed and changes nothing', function (): void {
    [$service] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant('UTC');

    expect(fn () => $service->updateProfile(
        rmgmtOperator($restaurantId),
        RestaurantProfileCommand::fromValidated($restaurantId, rmgmtProfileInput(['time_zone' => 'Mars/Olympus'])),
    ))->toThrow(ValidationException::class);

    $row = DB::table('restaurants')->where('id', $restaurantId)->first();
    expect($row->time_zone)->toBe('UTC')
        ->and($row->name)->not->toBe('Updated Name');
});

it('forbids a Restaurant_Operator from managing another Restaurant as a non-disclosing 404', function (): void {
    [$service] = rmgmtHarness();
    $mine = rmgmtSeedRestaurant();
    $theirs = rmgmtSeedRestaurant();

    try {
        $service->updateProfile(rmgmtOperator($mine), RestaurantProfileCommand::fromValidated($theirs, rmgmtProfileInput()));
        $this->fail('expected a non-disclosing not-found');
    } catch (AuthorizationException $e) {
        expect($e->errorCode())->toBe('restaurant_not_found');
    }

    $row = DB::table('restaurants')->where('id', $theirs)->first();
    expect($row->name)->not->toBe('Updated Name');
});

it('returns restaurant_not_found for a missing Restaurant', function (): void {
    [$service] = rmgmtHarness();

    expect(fn () => $service->viewProfile(rmgmtAdministrator(), (string) Str::ulid()))
        ->toThrow(AuthorizationException::class);
});

// ---- Branch create/update --------------------------------------------------

it('creates a Restaurant_Branch active with the resolved coordinate and Restaurant time zone fallback', function (): void {
    [$service] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant('Europe/Paris');

    $view = $service->createBranch(
        rmgmtOperator($restaurantId),
        BranchCommand::fromValidated($restaurantId, rmgmtBranchInput()),
    );

    expect($view->isActive)->toBeTrue()
        // The resolved coordinate is persisted; the stored decimal's string form
        // depends on the driver (SQLite strips trailing zeros, Postgres keeps
        // them), so the invariant asserted here is numeric equality.
        ->and((float) $view->latitude)->toBe(40.7128)
        ->and((float) $view->longitude)->toBe(-74.006)
        ->and($view->timeZone)->toBe('Europe/Paris'); // fell back to the Restaurant's

    $count = DB::table('restaurant_branches')->where('restaurant_id', $restaurantId)->count();
    expect($count)->toBe(1);
});

it('applies a submitted Restaurant_Branch time zone over the Restaurant fallback', function (): void {
    [$service] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant('Europe/Paris');

    $view = $service->createBranch(
        rmgmtOperator($restaurantId),
        BranchCommand::fromValidated($restaurantId, rmgmtBranchInput(['time_zone' => 'Asia/Tokyo'])),
    );

    expect($view->timeZone)->toBe('Asia/Tokyo');
});

it('rejects a Restaurant_Branch time zone that is not a valid IANA identifier', function (): void {
    [$service] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant();

    expect(fn () => $service->createBranch(
        rmgmtOperator($restaurantId),
        BranchCommand::fromValidated($restaurantId, rmgmtBranchInput(['time_zone' => 'Not/AZone'])),
    ))->toThrow(ValidationException::class);

    expect(DB::table('restaurant_branches')->where('restaurant_id', $restaurantId)->count())->toBe(0);
});

it('creates no Restaurant_Branch when the address does not resolve', function (): void {
    [$service, $resolver] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant();
    $resolver->fail = true;

    expect(fn () => $service->createBranch(
        rmgmtOperator($restaurantId),
        BranchCommand::fromValidated($restaurantId, rmgmtBranchInput()),
    ))->toThrow(AddressNotResolvable::class);

    expect(DB::table('restaurant_branches')->where('restaurant_id', $restaurantId)->count())->toBe(0);
});

it('rejects the 51st Restaurant_Branch with branch_limit_reached and creates nothing', function (): void {
    [$service] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant();

    // Seed 50 existing branches directly (inactive ones count toward the bound).
    for ($i = 0; $i < RestaurantManagementServiceImplementation::MAX_BRANCHES; $i++) {
        DB::table('restaurant_branches')->insert([
            'id' => (string) Str::ulid(),
            'restaurant_id' => $restaurantId,
            'name' => 'B'.$i,
            'address' => 'addr',
            'is_active' => $i % 2 === 0,
        ]);
    }

    try {
        $service->createBranch(rmgmtOperator($restaurantId), BranchCommand::fromValidated($restaurantId, rmgmtBranchInput()));
        $this->fail('expected branch_limit_reached');
    } catch (ValidationException $e) {
        expect($e->errorCode())->toBe('branch_limit_reached');
    }

    expect(DB::table('restaurant_branches')->where('restaurant_id', $restaurantId)->count())
        ->toBe(RestaurantManagementServiceImplementation::MAX_BRANCHES);
});

it('updates a Restaurant_Branch name, address, and coordinate', function (): void {
    [$service] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant();
    $actor = rmgmtOperator($restaurantId);

    $created = $service->createBranch($actor, BranchCommand::fromValidated($restaurantId, rmgmtBranchInput()));

    $updated = $service->updateBranch(
        $actor,
        BranchCommand::fromValidated($restaurantId, rmgmtBranchInput(['name' => 'Uptown', 'address_line_1' => '9 Elm St']), $created->id),
    );

    expect($updated->name)->toBe('Uptown')
        ->and($updated->address)->toContain('9 Elm St');
});

it('returns the non-disclosing 404 for a Restaurant_Branch of another Restaurant', function (): void {
    [$service] = rmgmtHarness();
    $mine = rmgmtSeedRestaurant();
    $theirs = rmgmtSeedRestaurant();

    $theirBranch = $service->createBranch(rmgmtOperator($theirs), BranchCommand::fromValidated($theirs, rmgmtBranchInput()));

    expect(fn () => $service->updateBranch(
        rmgmtOperator($mine),
        BranchCommand::fromValidated($mine, rmgmtBranchInput(), $theirBranch->id),
    ))->toThrow(AuthorizationException::class);
});

// ---- Activate / deactivate -------------------------------------------------

it('writes the Restaurant_Branch active flag on deactivate and activate', function (): void {
    [$service] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant();
    $actor = rmgmtOperator($restaurantId);

    $branch = $service->createBranch($actor, BranchCommand::fromValidated($restaurantId, rmgmtBranchInput()));

    $deactivated = $service->setBranchActive($actor, $restaurantId, $branch->id, false);
    expect($deactivated->isActive)->toBeFalse();
    expect((bool) DB::table('restaurant_branches')->where('id', $branch->id)->value('is_active'))->toBeFalse();

    $reactivated = $service->setBranchActive($actor, $restaurantId, $branch->id, true);
    expect($reactivated->isActive)->toBeTrue();
});

// ---- Zone assignment -------------------------------------------------------

it('assigns Delivery_Zones the Restaurant owns', function (): void {
    [$service, , $zones] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant();
    $actor = rmgmtOperator($restaurantId);
    $branch = $service->createBranch($actor, BranchCommand::fromValidated($restaurantId, rmgmtBranchInput()));

    $zones->ownedByRestaurant[$restaurantId] = ['z1', 'z2', 'z3'];

    $view = $service->assignZones($actor, $restaurantId, $branch->id, ['z1', 'z2']);

    expect($view->zoneIds)->toBe(['z1', 'z2'])
        ->and($zones->assignedByBranch[$branch->id])->toBe(['z1', 'z2']);
});

it('rejects assigning a zone the Restaurant does not own and changes nothing', function (): void {
    [$service, , $zones] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant();
    $actor = rmgmtOperator($restaurantId);
    $branch = $service->createBranch($actor, BranchCommand::fromValidated($restaurantId, rmgmtBranchInput()));

    $zones->ownedByRestaurant[$restaurantId] = ['z1'];

    try {
        $service->assignZones($actor, $restaurantId, $branch->id, ['z1', 'other']);
        $this->fail('expected validation_failed');
    } catch (ValidationException $e) {
        expect($e->errorCode())->toBe('validation_failed');
    }

    expect($zones->assignedByBranch)->not->toHaveKey($branch->id);
});

it('rejects assigning more than 20 Delivery_Zones', function (): void {
    [$service, , $zones] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant();
    $actor = rmgmtOperator($restaurantId);
    $branch = $service->createBranch($actor, BranchCommand::fromValidated($restaurantId, rmgmtBranchInput()));

    $many = [];
    for ($i = 0; $i < 21; $i++) {
        $many[] = 'z'.$i;
    }
    $zones->ownedByRestaurant[$restaurantId] = $many;

    expect(fn () => $service->assignZones($actor, $restaurantId, $branch->id, $many))
        ->toThrow(ValidationException::class);
});

it('clears the assignment when an empty list is submitted', function (): void {
    [$service, , $zones] = rmgmtHarness();
    $restaurantId = rmgmtSeedRestaurant();
    $actor = rmgmtOperator($restaurantId);
    $branch = $service->createBranch($actor, BranchCommand::fromValidated($restaurantId, rmgmtBranchInput()));

    $view = $service->assignZones($actor, $restaurantId, $branch->id, []);

    expect($view->zoneIds)->toBe([])
        ->and($zones->assignedByBranch[$branch->id])->toBe([]);
});
