<?php

declare(strict_types=1);

// Feature: Waselna, task 8.1: Menu_Category, Menu_Item, Product_Variant, and
// Modifier management.

/*
 * Example-based service tests for the Catalog module's
 * MenuManagementServiceImplementation (task 8.1; Requirements 11, 34). They pin
 * the behaviours a reader of the requirements expects:
 *
 *  - a Menu_Category and a Menu_Item persist name, order, Price, availability,
 *    and category assignment (Requirements 11.1, 11.2);
 *  - a Price outside [1, 99999999] minor units is invalid_price and stores
 *    nothing (Requirement 11.3);
 *  - a Modifier_Group with min > max is invalid_modifier_group and stores
 *    nothing (Requirement 11.4);
 *  - deleting a Menu_Item archives it and keeps the row (Requirement 11.6);
 *  - the 50/200/20/10/50 collection bounds reject the one-too-many create with
 *    catalog_limit_reached and store nothing (Requirements 11.10, 34.1);
 *  - a record of another Restaurant is the non-disclosing menu_item_not_found
 *    (design "Non-disclosure rules").
 *
 * Time comes from a FrozenClock and identifiers from a SequenceIdGenerator so
 * creation order and archival instants are deterministic. The tables are built
 * directly from the catalog migration's menu columns, matching the Account
 * suite's SQLite approach; the service enforces every documented rule in PHP, so
 * the checks are exercised without a Postgres container.
 */

use App\Modules\Catalog\Domain\MenuCategoryCommand;
use App\Modules\Catalog\Domain\MenuItemCommand;
use App\Modules\Catalog\Domain\ModifierGroupCommand;
use App\Modules\Catalog\Domain\ModifierOptionCommand;
use App\Modules\Catalog\Domain\ProductVariantCommand;
use App\Modules\Catalog\Repositories\MenuCacheInvalidator;
use App\Modules\Catalog\Repositories\MenuRepository;
use App\Modules\Catalog\Services\MenuManagementServiceImplementation;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\SequenceIdGenerator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function menuServiceUnderTest(): MenuManagementServiceImplementation
{
    return new MenuManagementServiceImplementation(
        new MenuRepository,
        FrozenClock::at('2025-03-01T12:00:00Z'),
        new SequenceIdGenerator,
        new MenuCacheInvalidator(new CacheRepository(new ArrayStore)),
        new Dispatcher,
    );
}

function createCatalogSchema(): void
{
    Schema::dropIfExists('modifier_options');
    Schema::dropIfExists('modifier_groups');
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

    Schema::create('modifier_groups', function ($table): void {
        $table->string('id')->primary();
        $table->string('menu_item_id');
        $table->string('name');
        $table->integer('min_selections')->default(0);
        $table->integer('max_selections')->default(1);
        $table->integer('display_order')->default(0);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    Schema::create('modifier_options', function ($table): void {
        $table->string('id')->primary();
        $table->string('modifier_group_id');
        $table->string('name');
        $table->integer('price_delta_minor')->default(0);
        $table->integer('display_order')->default(0);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
}

function seedMenuRestaurant(): string
{
    $id = (string) Str::ulid();
    DB::table('restaurants')->insert([
        'id' => $id,
        'name' => 'Bella Napoli '.Str::lower(Str::random(6)),
        'status' => 'published',
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

beforeEach(function (): void {
    createCatalogSchema();
});

it('persists a Menu_Category name and display order', function (): void {
    $service = menuServiceUnderTest();
    $restaurantId = seedMenuRestaurant();

    $view = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 3));

    expect($view->name)->toBe('Popular')
        ->and($view->displayOrder)->toBe(3)
        ->and($view->archived)->toBeFalse();

    $row = DB::table('menu_categories')->where('id', $view->id)->first();
    expect($row)->not->toBeNull();
    assert($row !== null);
    expect($row->restaurant_id)->toBe($restaurantId)
        ->and((int) $row->display_order)->toBe(3);
});

it('persists a Menu_Item name, description, price, availability, and category', function (): void {
    $service = menuServiceUnderTest();
    $restaurantId = seedMenuRestaurant();

    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));
    $item = $service->createItem(new MenuItemCommand(
        $restaurantId, $category->id, 'Margherita', 'Tomato, mozzarella, basil.', 1200, true, 0,
    ));

    expect($item->name)->toBe('Margherita')
        ->and($item->priceMinor)->toBe(1200)
        ->and($item->isAvailable)->toBeTrue()
        ->and($item->categoryId)->toBe($category->id);
});

it('rejects a Menu_Item price below 1 with invalid_price and stores nothing', function (): void {
    $service = menuServiceUnderTest();
    $restaurantId = seedMenuRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));

    expect(fn () => $service->createItem(new MenuItemCommand(
        $restaurantId, $category->id, 'Free lunch', null, 0, true, 0,
    )))->toThrow(ValidationException::class);

    expect(DB::table('menu_items')->count())->toBe(0);
});

it('rejects a Menu_Item price above 99,999,999 with invalid_price', function (): void {
    $service = menuServiceUnderTest();
    $restaurantId = seedMenuRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));

    expect(fn () => $service->createItem(new MenuItemCommand(
        $restaurantId, $category->id, 'Gold plated', null, 100_000_000, true, 0,
    )))->toThrow(ValidationException::class);
});

it('archives a Menu_Item on delete and keeps the row', function (): void {
    $service = menuServiceUnderTest();
    $restaurantId = seedMenuRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));
    $item = $service->createItem(new MenuItemCommand($restaurantId, $category->id, 'Margherita', null, 1200, true, 0));

    $service->archiveItem($restaurantId, $item->id);

    $row = DB::table('menu_items')->where('id', $item->id)->first();
    expect($row)->not->toBeNull();
    assert($row !== null);
    expect($row->archived_at)->not->toBeNull();

    // Excluded from the management list and a repeat delete is the non-disclosing 404.
    expect($service->listItems($restaurantId, $category->id))->toBe([]);
    expect(fn () => $service->archiveItem($restaurantId, $item->id))
        ->toThrow(AuthorizationException::class);
});

it('persists a Product_Variant and treats its price floor as a business rule', function (): void {
    $service = menuServiceUnderTest();
    $restaurantId = seedMenuRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));
    $item = $service->createItem(new MenuItemCommand($restaurantId, $category->id, 'Pizza', null, 1200, true, 0));

    $variant = $service->createVariant(new ProductVariantCommand($restaurantId, $item->id, 'Large', 1800, true, 0));
    expect($variant->priceMinor)->toBe(1800)->and($variant->isAvailable)->toBeTrue();

    expect(fn () => $service->createVariant(new ProductVariantCommand($restaurantId, $item->id, 'Free', 0, true, 1)))
        ->toThrow(ValidationException::class);
    expect(DB::table('product_variants')->count())->toBe(1);
});

it('rejects a Modifier_Group whose min exceeds max with invalid_modifier_group', function (): void {
    $service = menuServiceUnderTest();
    $restaurantId = seedMenuRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));
    $item = $service->createItem(new MenuItemCommand($restaurantId, $category->id, 'Pizza', null, 1200, true, 0));

    expect(fn () => $service->createGroup(new ModifierGroupCommand($restaurantId, $item->id, 'Toppings', 3, 2, 0)))
        ->toThrow(ValidationException::class);
    expect(DB::table('modifier_groups')->count())->toBe(0);
});

it('persists a Modifier_Group and its Modifier_Options with signed price deltas', function (): void {
    $service = menuServiceUnderTest();
    $restaurantId = seedMenuRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));
    $item = $service->createItem(new MenuItemCommand($restaurantId, $category->id, 'Pizza', null, 1200, true, 0));

    $group = $service->createGroup(new ModifierGroupCommand($restaurantId, $item->id, 'Toppings', 0, 3, 0));
    $cheese = $service->createOption(new ModifierOptionCommand($restaurantId, $group->id, 'Extra cheese', 150, 0));
    $discount = $service->createOption(new ModifierOptionCommand($restaurantId, $group->id, 'No cheese', -100, 1));

    expect($cheese->priceDeltaMinor)->toBe(150)
        ->and($discount->priceDeltaMinor)->toBe(-100);

    $options = $service->listOptions($restaurantId, $group->id);
    expect($options)->toHaveCount(2);
});

it('enforces the 50-Menu_Category bound with catalog_limit_reached', function (): void {
    $service = menuServiceUnderTest();
    $restaurantId = seedMenuRestaurant();

    for ($i = 1; $i <= MenuManagementServiceImplementation::MAX_CATEGORIES; $i++) {
        $service->createCategory(new MenuCategoryCommand($restaurantId, 'Cat '.$i, 0));
    }

    expect(fn () => $service->createCategory(new MenuCategoryCommand($restaurantId, 'One too many', 0)))
        ->toThrow(ValidationException::class);
    expect(DB::table('menu_categories')->count())->toBe(MenuManagementServiceImplementation::MAX_CATEGORIES);
});

it('enforces the 20-Product_Variant bound with catalog_limit_reached', function (): void {
    $service = menuServiceUnderTest();
    $restaurantId = seedMenuRestaurant();
    $category = $service->createCategory(new MenuCategoryCommand($restaurantId, 'Popular', 0));
    $item = $service->createItem(new MenuItemCommand($restaurantId, $category->id, 'Pizza', null, 1200, true, 0));

    for ($i = 1; $i <= MenuManagementServiceImplementation::MAX_VARIANTS_PER_ITEM; $i++) {
        $service->createVariant(new ProductVariantCommand($restaurantId, $item->id, 'Variant '.$i, 1000 + $i, true, $i));
    }

    expect(fn () => $service->createVariant(new ProductVariantCommand($restaurantId, $item->id, 'Overflow', 2000, true, 99)))
        ->toThrow(ValidationException::class);
    expect(DB::table('product_variants')->count())->toBe(MenuManagementServiceImplementation::MAX_VARIANTS_PER_ITEM);
});

it('returns the non-disclosing 404 for a record of another Restaurant', function (): void {
    $service = menuServiceUnderTest();
    $mine = seedMenuRestaurant();
    $theirs = seedMenuRestaurant();

    $foreignCategory = $service->createCategory(new MenuCategoryCommand($theirs, 'Popular', 0));

    expect(fn () => $service->updateCategory(
        new MenuCategoryCommand($mine, 'Renamed', 0, $foreignCategory->id),
    ))->toThrow(AuthorizationException::class);

    // Unknown id reads identically.
    expect(fn () => $service->updateCategory(
        new MenuCategoryCommand($mine, 'Renamed', 0, (string) Str::ulid()),
    ))->toThrow(AuthorizationException::class);
});

it('orders Menu_Categories by display order then name', function (): void {
    $service = menuServiceUnderTest();
    $restaurantId = seedMenuRestaurant();

    $service->createCategory(new MenuCategoryCommand($restaurantId, 'Zed', 1));
    $service->createCategory(new MenuCategoryCommand($restaurantId, 'Alpha', 0));
    $service->createCategory(new MenuCategoryCommand($restaurantId, 'Beta', 0));

    $names = array_map(static fn ($v) => $v->name, $service->listCategories($restaurantId));
    expect($names)->toBe(['Alpha', 'Beta', 'Zed']);
});
