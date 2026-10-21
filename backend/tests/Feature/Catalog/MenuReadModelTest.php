<?php

declare(strict_types=1);

// Feature: Waselna, task 8.3: the Menu read model served with CacheOrFallback.

/*
 * Example-based service tests for MenuQueryServiceImplementation (task 8.3;
 * Requirements 4.1–4.9, 21.2–21.4). They pin the behaviours a reader of the
 * requirements expects of the Customer-facing Menu read:
 *
 *  - the read model carries every Menu_Category and Menu_Item, ordered at both
 *    levels by ascending display-order then ascending name (Requirement 4.1);
 *  - an unavailable Menu_Item is included in full, marked unavailable, not
 *    omitted (Requirement 4.4);
 *  - a Modifier_Group carries its Modifier_Options with signed Price_Deltas and
 *    the currency sits once at the top (Requirement 4.2);
 *  - a Restaurant with no Menu_Category, or a Menu_Category with no Menu_Item,
 *    returns empty collections rather than an error (Requirement 4.7);
 *  - a cold read builds from PostgreSQL and leaves a cache entry whose content
 *    equals the response with a 300-second expiry (Requirement 4.8);
 *  - a warm read is served from the cache entry and equals the cold read
 *    (Requirements 4.5, 21.2);
 *  - an identifier matching no published Restaurant is the non-disclosing 404
 *    and caches nothing (Requirement 4.9);
 *  - a Cache_Store outage degrades to PostgreSQL, returns the same value, and
 *    records the failure in the Observability_Stack (Requirement 21.4).
 *
 * Helpers are prefixed `menuRead` so they never collide with the task 8.1 / 8.2
 * suites' global helpers loaded in the same Pest run. Identifiers come from a
 * SequenceIdGenerator and instants from a FrozenClock so the stored Menu is
 * deterministic; the Cache_Store is an ArrayStore owned by each test so the
 * assertions observe exactly what the Service populated and read.
 */

use App\Modules\Catalog\Contracts\Services\MenuQueryService;
use App\Modules\Catalog\Domain\MenuCategoryCommand;
use App\Modules\Catalog\Domain\MenuItemCommand;
use App\Modules\Catalog\Domain\MenuReadModel;
use App\Modules\Catalog\Domain\ModifierGroupCommand;
use App\Modules\Catalog\Domain\ModifierOptionCommand;
use App\Modules\Catalog\Repositories\MenuCacheInvalidator;
use App\Modules\Catalog\Repositories\MenuReadModelRepository;
use App\Modules\Catalog\Repositories\MenuRepository;
use App\Modules\Catalog\Services\MenuManagementServiceImplementation;
use App\Modules\Catalog\Services\MenuQueryServiceImplementation;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Support\CacheOrFallback;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\SequenceIdGenerator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A LoggerInterface that captures the entries written to it, so a test can
 * assert the Cache_Store fallback is recorded in the Observability_Stack
 * (Requirement 21.4) without a live log channel.
 */
final class MenuReadSpyLogger extends NullLogger
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}

/**
 * A thin Cache_Store decorator that records the TTL every `put` was given, so a
 * test can assert the Menu read model is stored with the 300-second expiry of
 * Requirement 4.8 without depending on an ArrayStore's private expiry bookkeeping.
 * All other calls pass straight through to a backing ArrayStore repository.
 */
final class MenuReadRecordingCache extends CacheRepository
{
    /** @var list<array{key: string, ttl: mixed}> */
    public array $puts = [];

    public function __construct()
    {
        parent::__construct(new ArrayStore);
    }

    public function put($key, $value, $ttl = null): bool
    {
        $this->puts[] = ['key' => (string) $key, 'ttl' => $ttl];

        return parent::put($key, $value, $ttl);
    }
}

/**
 * A Cache_Store that throws on every contact, standing in for an unreachable
 * Redis so the CacheOrFallback degradation path can be exercised
 * (Requirement 21.4, Property 14).
 */
final class MenuReadUnreachableCache implements CacheContract
{
    public function get($key, $default = null): mixed
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function put($key, $value, $ttl = null): bool
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function many(array $keys): array
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function putMany(array $values, $ttl = null): bool
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function add($key, $value, $ttl = null): bool
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function increment($key, $value = 1): int|bool
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function decrement($key, $value = 1): int|bool
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function forever($key, $value): bool
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function remember($key, $ttl, Closure $callback): mixed
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function sear($key, Closure $callback): mixed
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function rememberForever($key, Closure $callback): mixed
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function forget($key): bool
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function has($key): bool
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function pull($key, $default = null): mixed
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function delete($key): bool
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function getStore()
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    /** @param iterable<string> $keys */
    public function getMultiple($keys, $default = null): iterable
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple($values, $ttl = null): bool
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    /** @param iterable<string> $keys */
    public function deleteMultiple($keys): bool
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function clear(): bool
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }

    public function set($key, $value, $ttl = null): bool
    {
        throw new RuntimeException('Cache_Store unreachable.');
    }
}

/**
 * A write-side service wired for seeding the stored Menu, and a read-side service
 * over the given Cache_Store and logger.
 *
 * @return array{
 *     write: MenuManagementServiceImplementation,
 *     read: MenuQueryService,
 *     cache: CacheContract,
 *     logger: MenuReadSpyLogger
 * }
 */
function menuReadHarness(?CacheContract $cache = null, ?LoggerInterface $logger = null): array
{
    $cache ??= new CacheRepository(new ArrayStore);
    $spy = new MenuReadSpyLogger;

    $write = new MenuManagementServiceImplementation(
        new MenuRepository,
        FrozenClock::at('2025-03-01T12:00:00Z'),
        new SequenceIdGenerator,
        new MenuCacheInvalidator($cache instanceof CacheRepository ? $cache : new CacheRepository(new ArrayStore)),
        new Dispatcher,
    );

    $read = new MenuQueryServiceImplementation(
        new MenuReadModelRepository,
        new CacheOrFallback($cache, $logger ?? $spy),
    );

    return ['write' => $write, 'read' => $read, 'cache' => $cache, 'logger' => $spy];
}

function menuReadCreateSchema(): void
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

function menuReadSeedRestaurant(string $status = 'published', string $currency = 'USD'): string
{
    $id = (string) Str::ulid();
    DB::table('restaurants')->insert([
        'id' => $id,
        'name' => 'Trattoria '.Str::lower(Str::random(6)),
        'status' => $status,
        'currency' => $currency,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

beforeEach(function (): void {
    menuReadCreateSchema();
});

it('returns every category and item ordered by display-order then name', function (): void {
    ['write' => $write, 'read' => $read] = menuReadHarness();
    $restaurantId = menuReadSeedRestaurant();

    // Two categories sharing display-order 0 — the tie breaks by ascending name,
    // so "Beverages" precedes "Mains"; a third at display-order 1 comes last.
    $mains = $write->createCategory(new MenuCategoryCommand($restaurantId, 'Mains', 0));
    $bev = $write->createCategory(new MenuCategoryCommand($restaurantId, 'Beverages', 0));
    $write->createCategory(new MenuCategoryCommand($restaurantId, 'Desserts', 1));

    // Items in "Mains" with a display-order tie broken by ascending name.
    $write->createItem(new MenuItemCommand($restaurantId, $mains->id, 'Pizza', null, 1200, true, 0));
    $write->createItem(new MenuItemCommand($restaurantId, $mains->id, 'Burger', null, 1000, true, 0));
    $write->createItem(new MenuItemCommand($restaurantId, $mains->id, 'Soup', null, 800, true, 1));
    $write->createItem(new MenuItemCommand($restaurantId, $bev->id, 'Water', null, 200, true, 0));

    $menu = $read->menuFor($restaurantId);

    expect(array_map(fn ($c) => $c->name, $menu->categories))
        ->toBe(['Beverages', 'Mains', 'Desserts']);

    $mainsOut = $menu->categories[1];
    expect($mainsOut->name)->toBe('Mains')
        ->and(array_map(fn ($i) => $i->name, $mainsOut->items))
        ->toBe(['Burger', 'Pizza', 'Soup']);
});

it('includes an unavailable Menu_Item in full, marked unavailable', function (): void {
    ['write' => $write, 'read' => $read] = menuReadHarness();
    $restaurantId = menuReadSeedRestaurant();
    $cat = $write->createCategory(new MenuCategoryCommand($restaurantId, 'Mains', 0));
    $write->createItem(new MenuItemCommand($restaurantId, $cat->id, 'Sold Out Pie', 'A seasonal pie.', 1500, false, 0));

    $menu = $read->menuFor($restaurantId);
    $item = $menu->categories[0]->items[0];

    expect($item->name)->toBe('Sold Out Pie')
        ->and($item->description)->toBe('A seasonal pie.')
        ->and($item->priceMinor)->toBe(1500)
        ->and($item->isAvailable)->toBeFalse();
});

it('carries modifier groups with their options, signed price deltas, and the currency at the top', function (): void {
    ['write' => $write, 'read' => $read] = menuReadHarness();
    $restaurantId = menuReadSeedRestaurant(currency: 'EUR');
    $cat = $write->createCategory(new MenuCategoryCommand($restaurantId, 'Mains', 0));
    $item = $write->createItem(new MenuItemCommand($restaurantId, $cat->id, 'Build-a-Bowl', null, 900, true, 0));
    $group = $write->createGroup(new ModifierGroupCommand($restaurantId, $item->id, 'Extras', 0, 3, 0));
    $write->createOption(new ModifierOptionCommand($restaurantId, $group->id, 'Avocado', 150, 0));
    $write->createOption(new ModifierOptionCommand($restaurantId, $group->id, 'No Onion', -50, 1));

    $menu = $read->menuFor($restaurantId);

    expect($menu->currency)->toBe('EUR');
    $groupOut = $menu->categories[0]->items[0]->modifierGroups[0];
    expect($groupOut->name)->toBe('Extras')
        ->and($groupOut->minSelections)->toBe(0)
        ->and($groupOut->maxSelections)->toBe(3)
        ->and(array_map(fn ($o) => [$o->name, $o->priceDeltaMinor], $groupOut->options))
        ->toBe([['Avocado', 150], ['No Onion', -50]]);
});

it('returns empty collections for a Restaurant with no categories or an empty category', function (): void {
    ['write' => $write, 'read' => $read] = menuReadHarness();

    $bare = menuReadSeedRestaurant();
    expect($read->menuFor($bare)->categories)->toBe([]);

    $withEmptyCategory = menuReadSeedRestaurant();
    $write->createCategory(new MenuCategoryCommand($withEmptyCategory, 'Mains', 0));
    $menu = $read->menuFor($withEmptyCategory);
    expect($menu->categories)->toHaveCount(1)
        ->and($menu->categories[0]->items)->toBe([]);
});

it('cold read builds from PostgreSQL and stores the entry with a 300-second expiry', function (): void {
    $cache = new MenuReadRecordingCache;
    ['write' => $write, 'read' => $read] = menuReadHarness($cache);
    $restaurantId = menuReadSeedRestaurant();
    $cat = $write->createCategory(new MenuCategoryCommand($restaurantId, 'Mains', 0));
    $write->createItem(new MenuItemCommand($restaurantId, $cat->id, 'Pizza', null, 1200, true, 0));

    $key = MenuCacheInvalidator::menuKey($restaurantId);
    expect($cache->get($key))->toBeNull();

    $menu = $read->menuFor($restaurantId);

    // The entry now exists and its content equals the response (Requirement 4.8).
    expect($cache->get($key))->toBe($menu->toArray());

    // Exactly one populate, under the Menu key, with the 300-second TTL
    // (Requirements 4.8, 21.2).
    expect($cache->puts)->toHaveCount(1)
        ->and($cache->puts[0]['key'])->toBe($key)
        ->and($cache->puts[0]['ttl'])->toBe(MenuQueryServiceImplementation::CACHE_TTL_SECONDS);
});

it('warm read is served from the cache entry and equals the cold read', function (): void {
    ['write' => $write, 'read' => $read, 'cache' => $cache] = menuReadHarness();
    $restaurantId = menuReadSeedRestaurant();
    $cat = $write->createCategory(new MenuCategoryCommand($restaurantId, 'Mains', 0));
    $write->createItem(new MenuItemCommand($restaurantId, $cat->id, 'Pizza', null, 1200, true, 0));

    $cold = $read->menuFor($restaurantId);

    // Mutate the stored row directly; a warm read must still return the cached
    // value, proving the second read came from the Cache_Store rather than
    // PostgreSQL (Requirements 4.5, 21.2).
    DB::table('menu_items')->where('restaurant_id', $restaurantId)->update(['name' => 'Mutated']);

    $warm = $read->menuFor($restaurantId);

    expect($warm->toArray())->toBe($cold->toArray())
        ->and($warm->categories[0]->items[0]->name)->toBe('Pizza');
});

it('raises the non-disclosing 404 for an unknown or unpublished Restaurant and caches nothing', function (): void {
    ['read' => $read, 'cache' => $cache] = menuReadHarness();

    $draft = menuReadSeedRestaurant(status: 'draft');
    $unknown = (string) Str::ulid();

    expect(fn () => $read->menuFor($draft))->toThrow(AuthorizationException::class);
    expect(fn () => $read->menuFor($unknown))->toThrow(AuthorizationException::class);

    expect($cache->get(MenuCacheInvalidator::menuKey($draft)))->toBeNull()
        ->and($cache->get(MenuCacheInvalidator::menuKey($unknown)))->toBeNull();
});

it('degrades to PostgreSQL and records the failure when the Cache_Store is unreachable', function (): void {
    // Seed through a healthy write harness, then read through one whose
    // Cache_Store throws on contact.
    ['write' => $write] = menuReadHarness();
    $restaurantId = menuReadSeedRestaurant();
    $cat = $write->createCategory(new MenuCategoryCommand($restaurantId, 'Mains', 0));
    $write->createItem(new MenuItemCommand($restaurantId, $cat->id, 'Pizza', null, 1200, true, 0));

    $logger = new MenuReadSpyLogger;
    $read = new MenuQueryServiceImplementation(
        new MenuReadModelRepository,
        new CacheOrFallback(new MenuReadUnreachableCache, $logger),
    );

    $menu = $read->menuFor($restaurantId);

    expect($menu)->toBeInstanceOf(MenuReadModel::class)
        ->and($menu->categories[0]->items[0]->name)->toBe('Pizza')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['context']['event'])->toBe('cache.fallback')
        ->and($logger->records[0]['context']['cache_key'])->toBe(MenuCacheInvalidator::menuKey($restaurantId));
});
