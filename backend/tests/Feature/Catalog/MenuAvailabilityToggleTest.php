<?php

declare(strict_types=1);

// Feature: Waselna, task 8.2: availability toggling with event publication and
// cache invalidation.

/*
 * Example-based service tests for availability toggling on
 * MenuManagementServiceImplementation (task 8.2; Requirements 11.5, 34.5, 34.7,
 * 34.8, 6.4). They pin the behaviours a reader of the requirements expects:
 *
 *  - setting a Menu_Item or Product_Variant availability flag persists the
 *    submitted value (Requirement 34.7);
 *  - a change invalidates the Menu read model `menu:v1:{restaurantId}` and bumps
 *    the Restaurant's search version, both within the commit (Requirement 34.7);
 *  - setting the flag to false publishes exactly one
 *    `menu_item.availability_changed` event carrying the Menu_Item id, the
 *    optional Product_Variant id, the new flag, and the UTC timestamp
 *    (Requirements 34.8, 6.4);
 *  - setting the flag to true publishes no event (Requirement 34.8 names the
 *    false transition);
 *  - an idempotent write of the already-stored value publishes nothing and
 *    invalidates nothing further;
 *  - a record of another Restaurant is the non-disclosing menu_item_not_found;
 *  - a rolled-back write publishes nothing and evicts nothing (post-commit).
 *
 * Helpers are prefixed `avail` so they never collide with the task 8.1 suite's
 * global helpers loaded in the same Pest run. Time comes from a FrozenClock and
 * identifiers from a SequenceIdGenerator so instants and ids are deterministic.
 * The event dispatcher and Cache_Store are owned by each test so the assertions
 * observe exactly what the Service published and evicted.
 */

use App\Modules\Catalog\Domain\MenuCategoryCommand;
use App\Modules\Catalog\Domain\MenuItemCommand;
use App\Modules\Catalog\Domain\ProductVariantCommand;
use App\Modules\Catalog\Events\MenuItemAvailabilityChanged;
use App\Modules\Catalog\Repositories\MenuCacheInvalidator;
use App\Modules\Catalog\Repositories\MenuRepository;
use App\Modules\Catalog\Services\MenuManagementServiceImplementation;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\SequenceIdGenerator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A mutable sink the test dispatcher appends captured events to. Returned from
 * {@see availHarness} so array destructuring keeps a handle to the same object
 * rather than a copied snapshot.
 */
final class AvailabilityEventSink
{
    /** @var list<MenuItemAvailabilityChanged> */
    public array $events = [];
}

/**
 * @return array{
 *     service: MenuManagementServiceImplementation,
 *     cache: CacheContract,
 *     sink: AvailabilityEventSink
 * }
 */
function availHarness(): array
{
    $cache = new CacheRepository(new ArrayStore);

    $sink = new AvailabilityEventSink;
    $dispatcher = new Dispatcher;
    $dispatcher->listen(MenuItemAvailabilityChanged::class, function (MenuItemAvailabilityChanged $event) use ($sink): void {
        $sink->events[] = $event;
    });

    $service = new MenuManagementServiceImplementation(
        new MenuRepository,
        FrozenClock::at('2025-03-01T12:00:00Z'),
        new SequenceIdGenerator,
        new MenuCacheInvalidator($cache),
        $dispatcher,
    );

    return ['service' => $service, 'cache' => $cache, 'sink' => $sink];
}

function availCreateSchema(): void
{
    Schema::dropIfExists('product_variants');
    Schema::dropIfExists('menu_items');
    Schema::dropIfExists('menu_categories');
    Schema::dropIfExists('restaurants');

    Schema::create('restaurants', function ($table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->string('status', 20)->default('draft');
        $table->string('currency', 3)->default('USD');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    Schema::create('menu_categories', function ($table): void {
        $table->string('id')->primary();
        $table->string('restaurant_id');
        $table->string('name');
        $table->integer('display_order')->default(0);
        $table->timestamp('archived_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    Schema::create('menu_items', function ($table): void {
        $table->string('id')->primary();
        $table->string('restaurant_id');
        $table->string('category_id');
        $table->string('name');
        $table->text('description')->nullable();
        $table->integer('price_minor');
        $table->boolean('is_available')->default(true);
        $table->integer('display_order')->default(0);
        $table->timestamp('archived_at')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    Schema::create('product_variants', function ($table): void {
        $table->string('id')->primary();
        $table->string('menu_item_id');
        $table->string('name');
        $table->integer('price_minor');
        $table->boolean('is_available')->default(true);
        $table->integer('display_order')->default(0);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
}

function availSeedRestaurant(): string
{
    $id = (string) Str::ulid();
    DB::table('restaurants')->insert([
        'id' => $id,
        'name' => 'Trattoria '.Str::lower(Str::random(6)),
        'status' => 'published',
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

beforeEach(function (): void {
    availCreateSchema();
});

it('persists a Menu_Item availability change, publishes one event, and invalidates the cache', function (): void {
    ['service' => $service, 'cache' => $cache, 'sink' => $sink] = availHarness();
    $restaurantId = availSeedRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));
    $item = $service->createItem(new MenuItemCommand($restaurantId, $category->id, 'Margherita', null, 1200, true, 0));

    // A warm cache for this Restaurant stands in for the read model (task 8.3).
    $cache->forever(MenuCacheInvalidator::menuKey($restaurantId), ['cached' => true]);
    $cache->forever(MenuCacheInvalidator::searchVersionKey($restaurantId), 7);

    $view = $service->setItemAvailability($restaurantId, $item->id, false);

    expect($view->isAvailable)->toBeFalse();

    $row = DB::table('menu_items')->where('id', $item->id)->first();
    assert($row !== null);
    expect((bool) $row->is_available)->toBeFalse();

    // Cache invalidated within the commit: the Menu key is gone and the search
    // version advanced (Requirement 34.7).
    expect($cache->get(MenuCacheInvalidator::menuKey($restaurantId)))->toBeNull()
        ->and((int) $cache->get(MenuCacheInvalidator::searchVersionKey($restaurantId)))->toBe(8);

    // Exactly one event with the documented payload (Requirements 34.8, 6.4).
    expect($sink->events)->toHaveCount(1);
    $event = $sink->events[0];
    assert($event instanceof MenuItemAvailabilityChanged);
    expect($event->restaurantId)->toBe($restaurantId)
        ->and($event->menuItemId)->toBe($item->id)
        ->and($event->productVariantId)->toBeNull()
        ->and($event->isAvailable)->toBeFalse()
        ->and($event->occurredAt->format('Y-m-d\TH:i:s\Z'))->toBe('2025-03-01T12:00:00Z')
        ->and($event->toArray()['event'])->toBe('menu_item.availability_changed');
});

it('publishes no event when a Menu_Item is set available but still invalidates the cache', function (): void {
    ['service' => $service, 'cache' => $cache, 'sink' => $sink] = availHarness();
    $restaurantId = availSeedRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));
    $item = $service->createItem(new MenuItemCommand($restaurantId, $category->id, 'Margherita', null, 1200, false, 0));

    $cache->forever(MenuCacheInvalidator::menuKey($restaurantId), ['cached' => true]);

    $view = $service->setItemAvailability($restaurantId, $item->id, true);

    expect($view->isAvailable)->toBeTrue()
        ->and($sink->events)->toHaveCount(0)
        ->and($cache->get(MenuCacheInvalidator::menuKey($restaurantId)))->toBeNull();
});

it('publishes the Product_Variant identifier when a variant is set unavailable', function (): void {
    ['service' => $service, 'sink' => $sink] = availHarness();
    $restaurantId = availSeedRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));
    $item = $service->createItem(new MenuItemCommand($restaurantId, $category->id, 'Pizza', null, 1200, true, 0));
    $variant = $service->createVariant(new ProductVariantCommand($restaurantId, $item->id, 'Large', 1800, true, 0));

    $view = $service->setVariantAvailability($restaurantId, $item->id, $variant->id, false);

    expect($view->isAvailable)->toBeFalse()
        ->and($sink->events)->toHaveCount(1);

    $event = $sink->events[0];
    assert($event instanceof MenuItemAvailabilityChanged);
    expect($event->menuItemId)->toBe($item->id)
        ->and($event->productVariantId)->toBe($variant->id)
        ->and($event->isAvailable)->toBeFalse();

    $row = DB::table('product_variants')->where('id', $variant->id)->first();
    assert($row !== null);
    expect((bool) $row->is_available)->toBeFalse();
});

it('is idempotent: writing the already-stored value publishes nothing and invalidates nothing further', function (): void {
    ['service' => $service, 'cache' => $cache, 'sink' => $sink] = availHarness();
    $restaurantId = availSeedRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));
    $item = $service->createItem(new MenuItemCommand($restaurantId, $category->id, 'Margherita', null, 1200, false, 0));

    $cache->forever(MenuCacheInvalidator::menuKey($restaurantId), ['cached' => true]);

    // The item is already unavailable; re-asserting false changes nothing.
    $service->setItemAvailability($restaurantId, $item->id, false);

    expect($sink->events)->toHaveCount(0)
        ->and($cache->get(MenuCacheInvalidator::menuKey($restaurantId)))->toBe(['cached' => true]);
});

it('leaves other Menu_Items unchanged when one is toggled', function (): void {
    ['service' => $service] = availHarness();
    $restaurantId = availSeedRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));
    $toggled = $service->createItem(new MenuItemCommand($restaurantId, $category->id, 'Margherita', null, 1200, true, 0));
    $other = $service->createItem(new MenuItemCommand($restaurantId, $category->id, 'Calzone', null, 1500, true, 1));

    $service->setItemAvailability($restaurantId, $toggled->id, false);

    $otherRow = DB::table('menu_items')->where('id', $other->id)->first();
    assert($otherRow !== null);
    expect((bool) $otherRow->is_available)->toBeTrue();
});

it('returns the non-disclosing 404 for an item of another Restaurant or an unknown id', function (): void {
    ['service' => $service, 'sink' => $sink] = availHarness();
    $mine = availSeedRestaurant();
    $theirs = availSeedRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($theirs, 'Popular', 0));
    $foreign = $service->createItem(new MenuItemCommand($theirs, $category->id, 'Secret', null, 1200, true, 0));

    expect(fn () => $service->setItemAvailability($mine, $foreign->id, false))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $service->setItemAvailability($mine, (string) Str::ulid(), false))
        ->toThrow(AuthorizationException::class);

    // A rejected toggle persisted no change and published no event.
    $row = DB::table('menu_items')->where('id', $foreign->id)->first();
    assert($row !== null);
    expect((bool) $row->is_available)->toBeTrue()
        ->and($sink->events)->toHaveCount(0);
});

it('publishes nothing and evicts nothing when the surrounding transaction rolls back', function (): void {
    ['service' => $service, 'cache' => $cache, 'sink' => $sink] = availHarness();
    $restaurantId = availSeedRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));
    $item = $service->createItem(new MenuItemCommand($restaurantId, $category->id, 'Margherita', null, 1200, true, 0));

    $cache->forever(MenuCacheInvalidator::menuKey($restaurantId), ['cached' => true]);

    // An outer transaction that toggles then rolls back must leave no trace: the
    // after-commit event and invalidation never fire (design "post-commit
    // events").
    try {
        DB::transaction(function () use ($service, $restaurantId, $item): void {
            $service->setItemAvailability($restaurantId, $item->id, false);
            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
        // expected
    }

    $row = DB::table('menu_items')->where('id', $item->id)->first();
    assert($row !== null);
    expect((bool) $row->is_available)->toBeTrue()
        ->and($sink->events)->toHaveCount(0)
        ->and($cache->get(MenuCacheInvalidator::menuKey($restaurantId)))->toBe(['cached' => true]);
});
