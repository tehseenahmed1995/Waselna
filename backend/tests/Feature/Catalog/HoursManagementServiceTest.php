<?php

declare(strict_types=1);

// Feature: waselna, task 7.4: hours management endpoints with atomic replacement.

/*
 * Example-based service tests for the Catalog module's
 * HoursManagementServiceImplementation (task 7.4; Requirements 11.7, 11.8, 32.5,
 * 32.6, 40.8). The exhaustive atomic-replacement property is Property 31,
 * written separately in task 7.5; these tests pin the specific behaviours a
 * reader of the requirements expects:
 *
 *  - an accepted submission replaces the owner's complete stored schedule with
 *    exactly the submitted intervals, for both Opening_Hours and Branch_Hours
 *    (Requirement 11.7, 32.5);
 *  - a second accepted submission replaces the first — no interval of the first
 *    survives (Requirement 32.6);
 *  - a submission breaking an interval rule is rejected with invalid_hours and
 *    the stored schedule is left byte-unchanged, with no interval of the
 *    submitted schedule applied (Requirement 11.8, 32.6);
 *  - a Restaurant_Operator may manage only its own Restaurant's hours; an
 *    Administrator may manage any; another Restaurant's Operator is forbidden;
 *  - a missing owner reads as the non-disclosing restaurant_not_found.
 *
 * The hours tables are built directly from the task 7.4 migration's columns
 * rather than by running the PostGIS- and btree_gist-bearing migration set,
 * matching the Account suite's SQLite approach; the database-level EXCLUDE
 * overlap constraint is exercised separately against Postgres in the Integration
 * suite. Identifiers come from a SequenceIdGenerator so row creation is
 * deterministic.
 */

use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Catalog\Repositories\HoursRepository;
use App\Modules\Catalog\Services\HoursManagementServiceImplementation;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\SequenceIdGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function hoursServiceUnderTest(): HoursManagementServiceImplementation
{
    return new HoursManagementServiceImplementation(
        new HoursRepository(new SequenceIdGenerator),
    );
}

function createHoursSchema(): void
{
    Schema::dropIfExists('branch_hours');
    Schema::dropIfExists('opening_hours');
    Schema::dropIfExists('restaurant_branches');
    Schema::dropIfExists('restaurants');

    Schema::create('restaurants', function ($table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('status', 20)->default('draft');
        $table->string('currency', 3)->default('USD');
        $table->string('time_zone', 64)->default('UTC');
    });

    Schema::create('restaurant_branches', function ($table): void {
        $table->string('id')->primary();
        $table->string('restaurant_id');
        $table->string('name');
        $table->string('address')->nullable();
        $table->string('time_zone', 64)->nullable();
        $table->boolean('is_active')->default(true);
    });

    foreach (['opening_hours' => 'restaurant_id', 'branch_hours' => 'restaurant_branch_id'] as $table => $owner) {
        Schema::create($table, function ($blueprint) use ($owner): void {
            $blueprint->string('id')->primary();
            $blueprint->string($owner);
            $blueprint->string('day_of_week', 9);
            $blueprint->integer('start_minute');
            $blueprint->integer('end_minute');
        });
    }
}

function seedRestaurant(): string
{
    $id = (string) Str::ulid();
    DB::table('restaurants')->insert([
        'id' => $id, 'name' => 'Resto '.Str::random(5), 'status' => 'draft', 'currency' => 'USD', 'time_zone' => 'UTC',
    ]);

    return $id;
}

function seedBranch(string $restaurantId): string
{
    $id = (string) Str::ulid();
    DB::table('restaurant_branches')->insert([
        'id' => $id, 'restaurant_id' => $restaurantId, 'name' => 'Branch', 'is_active' => true,
    ]);

    return $id;
}

function operatorFor(string $restaurantId): ActorContext
{
    return new ActorContext(
        userId: (string) Str::ulid(),
        role: 'restaurant_operator',
        accountStatus: 'active',
        restaurantId: $restaurantId,
    );
}

function administrator(): ActorContext
{
    return new ActorContext((string) Str::ulid(), 'administrator', 'active');
}

function slot(int $start, int $end): array
{
    return ['start_minute' => $start, 'end_minute' => $end];
}

beforeEach(function (): void {
    createHoursSchema();
});

it('replaces a Restaurant Opening_Hours with exactly the submitted intervals', function (): void {
    $service = hoursServiceUnderTest();
    $restaurantId = seedRestaurant();

    $service->replaceOpeningHours(operatorFor($restaurantId), $restaurantId, [
        'monday' => [slot(540, 720), slot(780, 1020)],
        'friday' => [slot(0, 1440)],
    ]);

    $rows = DB::table('opening_hours')->where('restaurant_id', $restaurantId)
        ->orderBy('day_of_week')->orderBy('start_minute')->get();

    expect($rows)->toHaveCount(3);

    $byDay = [];
    foreach ($rows as $r) {
        $byDay[$r->day_of_week][] = [$r->start_minute, $r->end_minute];
    }

    expect($byDay['monday'])->toBe([[540, 720], [780, 1020]])
        ->and($byDay['friday'])->toBe([[0, 1440]]);
});

it('replaces the complete stored schedule so no interval of the first submission survives', function (): void {
    $service = hoursServiceUnderTest();
    $restaurantId = seedRestaurant();
    $actor = operatorFor($restaurantId);

    $service->replaceOpeningHours($actor, $restaurantId, ['monday' => [slot(0, 600)]]);
    $service->replaceOpeningHours($actor, $restaurantId, ['tuesday' => [slot(600, 1200)]]);

    $rows = DB::table('opening_hours')->where('restaurant_id', $restaurantId)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->day_of_week)->toBe('tuesday')
        ->and($rows[0]->start_minute)->toBe(600);
});

it('clears the whole schedule when an empty submission is accepted', function (): void {
    $service = hoursServiceUnderTest();
    $restaurantId = seedRestaurant();
    $actor = operatorFor($restaurantId);

    $service->replaceOpeningHours($actor, $restaurantId, ['monday' => [slot(0, 600)]]);
    $service->replaceOpeningHours($actor, $restaurantId, []);

    expect(DB::table('opening_hours')->where('restaurant_id', $restaurantId)->count())->toBe(0);
});

it('replaces Branch_Hours for a Restaurant_Branch', function (): void {
    $service = hoursServiceUnderTest();
    $restaurantId = seedRestaurant();
    $branchId = seedBranch($restaurantId);

    $service->replaceBranchHours(operatorFor($restaurantId), $branchId, [
        'saturday' => [slot(600, 900)],
    ]);

    $rows = DB::table('branch_hours')->where('restaurant_branch_id', $branchId)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->day_of_week)->toBe('saturday');
});

it('rejects an overlapping submission with invalid_hours and leaves the stored schedule unchanged', function (): void {
    $service = hoursServiceUnderTest();
    $restaurantId = seedRestaurant();
    $actor = operatorFor($restaurantId);

    // A valid starting schedule.
    $service->replaceOpeningHours($actor, $restaurantId, ['monday' => [slot(540, 720)]]);

    $before = DB::table('opening_hours')->where('restaurant_id', $restaurantId)->get()->toArray();

    // An overlapping submission must change nothing.
    expect(fn () => $service->replaceOpeningHours($actor, $restaurantId, [
        'monday' => [slot(540, 720), slot(700, 900)],
    ]))->toThrow(ValidationException::class);

    $after = DB::table('opening_hours')->where('restaurant_id', $restaurantId)->get()->toArray();

    expect($after)->toEqual($before);
});

it('rejects a bad-bounds submission with invalid_hours and applies no interval', function (): void {
    $service = hoursServiceUnderTest();
    $restaurantId = seedRestaurant();
    $actor = operatorFor($restaurantId);

    expect(fn () => $service->replaceOpeningHours($actor, $restaurantId, [
        'monday' => [slot(0, 1441)],
    ]))->toThrow(ValidationException::class);

    // Nothing stored at all — the rejection happened before any write.
    expect(DB::table('opening_hours')->where('restaurant_id', $restaurantId)->count())->toBe(0);
});

it('lets an Administrator manage any Restaurant hours', function (): void {
    $service = hoursServiceUnderTest();
    $restaurantId = seedRestaurant();

    $service->replaceOpeningHours(administrator(), $restaurantId, ['monday' => [slot(0, 600)]]);

    expect(DB::table('opening_hours')->where('restaurant_id', $restaurantId)->count())->toBe(1);
});

it('forbids a Restaurant_Operator from managing another Restaurant hours', function (): void {
    $service = hoursServiceUnderTest();
    $mine = seedRestaurant();
    $theirs = seedRestaurant();

    expect(fn () => $service->replaceOpeningHours(operatorFor($mine), $theirs, ['monday' => [slot(0, 600)]]))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('opening_hours')->where('restaurant_id', $theirs)->count())->toBe(0);
});

it('returns the non-disclosing restaurant_not_found for a missing owner', function (): void {
    $service = hoursServiceUnderTest();

    expect(fn () => $service->replaceOpeningHours(administrator(), (string) Str::ulid(), ['monday' => [slot(0, 600)]]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $service->replaceBranchHours(administrator(), (string) Str::ulid(), ['monday' => [slot(0, 600)]]))
        ->toThrow(AuthorizationException::class);
});
