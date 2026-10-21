<?php

declare(strict_types=1);

// Feature: Waselna, Property 13: Cached reads equal uncached reads and reflect every committed write

/*
 * For any Menu read request, the response served from the Cache_Store is
 * content-identical to the response built from PostgreSQL, a cold read leaves a
 * cache entry whose content equals the response with a 300-second expiry, and
 * after a committed availability change invalidates the affected key the next
 * read repopulates the key and reflects the change.
 *
 * **Validates: Requirements 4.5, 4.6, 4.8, 21.2, 21.3, 34.7, 59.7**
 *
 * The subject is the task 8.3 Menu read model — MenuQueryServiceImplementation
 * over MenuReadModelRepository and the shared App\Shared\Support\CacheOrFallback
 * — exercised together with the task 8.2 availability path:
 * MenuManagementServiceImplementation::setItemAvailability, which on a committed
 * change invokes MenuCacheInvalidator to forget `menu:v1:{restaurantId}`
 * (MenuCacheInvalidator::menuKey). Both sides share one Illuminate cache
 * repository instance, so an eviction by the write side is observed by the read
 * side exactly as it is in production.
 *
 * Two properties, one per `it`:
 *
 *  - *Cache equality (Requirement 4.5, 4.8, 21.2, 21.3).* For any generated
 *    Menu, the cold read is built from PostgreSQL and the warm read is served
 *    from the Cache_Store; the two are content-identical, the cold read leaves
 *    exactly one cache entry under the Menu key whose content equals the
 *    response, and that entry is stored with the 300-second TTL. The warm read
 *    is proven to come from the cache rather than PostgreSQL by mutating the
 *    stored rows between the two reads and asserting the warm read still returns
 *    the cold value.
 *
 *  - *Invalidation (Requirement 4.6, 34.7, 59.7).* For any generated Menu with
 *    at least one available item, a cold read populates the key; toggling an
 *    item's availability to false through the write service forgets the key; the
 *    next read repopulates it and reflects the new flag, with the repopulated
 *    entry content-equal to that fresh read.
 *
 * Database and determinism. The two-table-family Menu schema is built directly
 * from the task 8.1/8.3 migration columns — matching the Catalog Feature suite's
 * SQLite approach (tests/Feature/Catalog/MenuReadModelTest) — because the
 * property is about the service's cache choreography, not Postgres-only
 * constraints. phpunit.xml forces DB_CONNECTION=sqlite and DB_DATABASE=:memory:;
 * a CLI run against the pgsql container must set those explicitly because the
 * container .env defaults to pgsql. Each generated input runs inside a
 * transaction rolled back afterwards, as the Testing Strategy prescribes for
 * database-touching properties. Identifiers come from the injected
 * SequenceIdGenerator and instants from a FrozenClock, so the stored Menu and
 * every cache populate are deterministic; the Cache_Store is a per-input
 * ArrayStore-backed repository owned by the harness so assertions observe
 * exactly what the services populated, read, and evicted.
 *
 * All global helpers are prefixed `cacheEq85` so they never collide with the
 * same-named helpers of the Catalog Feature suite or the other Catalog property
 * tests loaded in the same Pest run.
 */

use App\Modules\Catalog\Contracts\Services\MenuQueryService;
use App\Modules\Catalog\Repositories\MenuCacheInvalidator;
use App\Modules\Catalog\Repositories\MenuReadModelRepository;
use App\Modules\Catalog\Repositories\MenuRepository;
use App\Modules\Catalog\Services\MenuManagementServiceImplementation;
use App\Modules\Catalog\Services\MenuQueryServiceImplementation;
use App\Shared\Support\CacheOrFallback;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\SequenceIdGenerator;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psr\Log\NullLogger;

uses(TestTrait::class);

/**
 * A thin Cache_Store decorator that records the TTL every `put` was given, so a
 * test can assert the Menu read model is stored with the 300-second expiry of
 * Requirement 4.8 without depending on an ArrayStore's private expiry bookkeeping.
 * All other calls pass straight through to a backing ArrayStore repository.
 */
final class CacheEq85RecordingCache extends CacheRepository
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
 * A write-side and read-side service pair sharing one Cache_Store repository, so
 * the write side's invalidation of the Menu key is observed by the read side —
 * the production wiring. The write side's MenuCacheInvalidator and the read
 * side's CacheOrFallback are both handed the identical repository.
 *
 * @return array{write: MenuManagementServiceImplementation, read: MenuQueryService, cache: CacheRepository}
 */
function cacheEq85Harness(CacheRepository $cache): array
{
    $write = new MenuManagementServiceImplementation(
        new MenuRepository,
        FrozenClock::at('2025-03-01T12:00:00Z'),
        new SequenceIdGenerator,
        new MenuCacheInvalidator($cache),
        new Dispatcher,
    );

    $read = new MenuQueryServiceImplementation(
        new MenuReadModelRepository,
        new CacheOrFallback($cache, new NullLogger),
    );

    return ['write' => $write, 'read' => $read, 'cache' => $cache];
}

/**
 * Builds the Menu schema directly from the task 8.1/8.3 migration columns, plus
 * the Restaurant table its foreign keys reference. Postgres-only CHECK and
 * exclusion constraints are omitted: this property is about cache choreography,
 * which is observable on the portable columns alone.
 */
function cacheEq85CreateSchema(): void
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

function cacheEq85SeedRestaurant(string $currency = 'USD'): string
{
    $id = (string) Str::ulid();
    DB::table('restaurants')->insert([
        'id' => $id,
        'name' => 'Trattoria '.Str::lower(Str::random(6)),
        'status' => 'published',
        'currency' => $currency,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * Seeds the generated Menu with direct bulk inserts rather than the write
 * service's per-record transaction chain, and returns the identifiers of every
 * Menu_Item so a later toggle can target one.
 *
 * The subject of this property is the read model and the cache choreography, not
 * the write path (the write path is covered by task 8.1's suite). Seeding the
 * rows directly keeps each generated input cheap enough to run 100+ of them
 * without exhausting memory, while the rows are byte-identical to what the write
 * service would have produced � the read model is still composed from them by
 * the real MenuReadModelRepository, so the equality and invalidation assertions
 * are unchanged. IDs come from the shared SequenceIdGenerator so creation order
 * is deterministic, and every row carries the same frozen timestamp.
 *
 * @param  array{categories: list<array{name: string, order: int, items: list<array{name: string, price: int, available: bool, order: int, groups: list<array{name: string, min: int, max: int, order: int, options: list<array{name: string, delta: int, order: int}>}>}>}>}  $menu
 * @return list<string>
 */
function cacheEq85SeedMenu(string $restaurantId, array $menu): array
{
    $ids = new SequenceIdGenerator;
    $now = '2025-03-01 12:00:00';

    $categoryRows = [];
    $itemRows = [];
    $groupRows = [];
    $optionRows = [];
    $itemIds = [];

    foreach ($menu['categories'] as $category) {
        $categoryId = $ids->ulid();
        $categoryRows[] = [
            'id' => $categoryId,
            'restaurant_id' => $restaurantId,
            'name' => $category['name'],
            'display_order' => $category['order'],
            'archived_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach ($category['items'] as $item) {
            $itemId = $ids->ulid();
            $itemIds[] = $itemId;
            $itemRows[] = [
                'id' => $itemId,
                'restaurant_id' => $restaurantId,
                'category_id' => $categoryId,
                'name' => $item['name'],
                'description' => null,
                'price_minor' => $item['price'],
                'is_available' => $item['available'],
                'display_order' => $item['order'],
                'archived_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach ($item['groups'] as $group) {
                $groupId = $ids->ulid();
                $groupRows[] = [
                    'id' => $groupId,
                    'menu_item_id' => $itemId,
                    'name' => $group['name'],
                    'min_selections' => $group['min'],
                    'max_selections' => $group['max'],
                    'display_order' => $group['order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                foreach ($group['options'] as $option) {
                    $optionRows[] = [
                        'id' => $ids->ulid(),
                        'modifier_group_id' => $groupId,
                        'name' => $option['name'],
                        'price_delta_minor' => $option['delta'],
                        'display_order' => $option['order'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }
    }

    if ($categoryRows !== []) {
        DB::table('menu_categories')->insert($categoryRows);
    }
    if ($itemRows !== []) {
        DB::table('menu_items')->insert($itemRows);
    }
    if ($groupRows !== []) {
        DB::table('modifier_groups')->insert($groupRows);
    }
    if ($optionRows !== []) {
        DB::table('modifier_options')->insert($optionRows);
    }

    return $itemIds;
}

/**
 * A complete generated Menu built from four small integer counts and an
 * availability bitmask, with deterministic element names. Using fixed `choose`
 * counts and plain loops instead of nested `seq`/`vector` generators keeps the
 * generated value shallow, so eris generation and shrinking stay cheap in both
 * time and memory across the 100 inputs � the deep `seq`-of-`seq` form this
 * replaces exhausted the test runner. The structure still varies across every
 * dimension the read model orders and projects: 1..3 categories, 0..3 items per
 * category, 0..2 modifier groups per item, 0..2 options per group, and a
 * per-item availability flag so both available and unavailable items occur.
 *
 * @return ErisGenerator<array{categories: list<array{name: string, order: int, items: list<array{name: string, price: int, available: bool, order: int, groups: list<array{name: string, min: int, max: int, order: int, options: list<array{name: string, delta: int, order: int}>}>}>}>}>
 */
function cacheEq85Menu(): ErisGenerator
{
    return Generators::map(
        static function (array $parts): array {
            [$categoryCount, $itemsPerCategory, $groupsPerItem, $optionsPerGroup, $availabilityMask] = $parts;

            $categories = [];
            $itemOrdinal = 0;

            for ($c = 0; $c < $categoryCount; $c++) {
                $items = [];

                for ($i = 0; $i < $itemsPerCategory; $i++) {
                    $groups = [];

                    for ($g = 0; $g < $groupsPerItem; $g++) {
                        $options = [];
                        for ($o = 0; $o < $optionsPerGroup; $o++) {
                            $options[] = [
                                'name' => 'opt-'.$c.'-'.$i.'-'.$g.'-'.$o,
                                'delta' => ($o % 2 === 0 ? 1 : -1) * (($o + 1) * 25),
                                'order' => $o,
                            ];
                        }

                        $groups[] = [
                            'name' => 'grp-'.$c.'-'.$i.'-'.$g,
                            'min' => 0,
                            'max' => max(1, $optionsPerGroup),
                            'order' => $g,
                            'options' => $options,
                        ];
                    }

                    // The availability bitmask gives each item, by ordinal, a
                    // deterministic-yet-varied available flag so both branches
                    // occur across generated inputs.
                    $available = (($availabilityMask >> ($itemOrdinal % 16)) & 1) === 1;
                    $itemOrdinal++;

                    $items[] = [
                        'name' => 'item-'.$c.'-'.$i,
                        'price' => 100 + ($c * 100) + ($i * 10),
                        'available' => $available,
                        'order' => $i,
                        'groups' => $groups,
                    ];
                }

                $categories[] = [
                    'name' => 'cat-'.$c,
                    'order' => $c,
                    'items' => $items,
                ];
            }

            return ['categories' => $categories];
        },
        Generators::tuple(
            Generators::choose(1, 3),   // categories
            Generators::choose(0, 3),   // items per category
            Generators::choose(0, 2),   // modifier groups per item
            Generators::choose(0, 2),   // options per group
            Generators::choose(0, 65535), // availability bitmask
        ),
    );
}
beforeEach(function (): void {
    cacheEq85CreateSchema();
});

it('serves a warm read content-identical to the cold read and caches it with a 300-second expiry', function (): void {
    $this->limitTo(100)->forAll(
        cacheEq85Menu(),
    )->then(function (array $menu): void {
        DB::beginTransaction();

        try {
            $cache = new CacheEq85RecordingCache;
            ['read' => $read] = cacheEq85Harness($cache);

            $restaurantId = cacheEq85SeedRestaurant();
            cacheEq85SeedMenu($restaurantId, $menu);

            $key = MenuCacheInvalidator::menuKey($restaurantId);
            expect($cache->get($key))->toBeNull();

            // Cold read: built from PostgreSQL, then stored.
            $cold = $read->menuFor($restaurantId);

            // Exactly one populate, under the Menu key, with the 300-second TTL
            // (Requirements 4.8, 21.2, 21.3).
            expect($cache->puts)->toHaveCount(1)
                ->and($cache->puts[0]['key'])->toBe($key)
                ->and($cache->puts[0]['ttl'])->toBe(MenuQueryServiceImplementation::CACHE_TTL_SECONDS);

            // The stored entry's content equals the response (Requirement 4.8).
            expect($cache->get($key))->toBe($cold->toArray());

            // Mutate the stored rows; a warm read must still return the cached
            // value, proving the second read came from the Cache_Store rather
            // than PostgreSQL (Requirements 4.5, 21.2).
            DB::table('menu_categories')->where('restaurant_id', $restaurantId)->update(['name' => 'Mutated']);
            DB::table('menu_items')->where('restaurant_id', $restaurantId)->update(['name' => 'Mutated']);

            $warm = $read->menuFor($restaurantId);

            // The warm read is content-identical to the cold read, and no second
            // populate happened (still exactly one put).
            expect($warm->toArray())->toBe($cold->toArray())
                ->and($cache->puts)->toHaveCount(1);
        } finally {
            DB::rollBack();
        }
    });
});

it('repopulates the key and reflects the change after an availability toggle invalidates it', function (): void {
    $this->limitTo(100)->forAll(
        cacheEq85Menu(),
    )->then(function (array $menu): void {
        // This property drives the real setItemAvailability write path, which
        // commits and queues its cache-invalidation + event work through
        // DB::afterCommit. A wrapping DB::beginTransaction()/rollBack() would
        // never let those afterCommit callbacks fire, so they would accumulate
        // across all 100 inputs and exhaust memory. Instead each input commits
        // for real and the Menu tables are truncated afterwards, which keeps the
        // afterCommit queue drained and the footprint flat.
        try {
            $cache = new CacheEq85RecordingCache;
            ['write' => $write, 'read' => $read] = cacheEq85Harness($cache);

            $restaurantId = cacheEq85SeedRestaurant();
            $itemIds = cacheEq85SeedMenu($restaurantId, $menu);

            // Ensure there is at least one item to toggle; a generated Menu may
            // have none, in which case this input exercises nothing to invalidate
            // and is skipped — the first property already covers item-free Menus.
            if ($itemIds === []) {
                return;
            }

            $key = MenuCacheInvalidator::menuKey($restaurantId);

            // Target the first item and flip it to the opposite of its current
            // availability, so the write is a genuine change. setItemAvailability
            // invalidates only on an actual change (task 8.2), so toggling a
            // value to what it already is would be a no-op and must not be used
            // to exercise invalidation.
            $targetItemId = $itemIds[0];
            $currentlyAvailable = (bool) DB::table('menu_items')->where('id', $targetItemId)->value('is_available');
            $newValue = ! $currentlyAvailable;

            // Cold read populates the key.
            $before = $read->menuFor($restaurantId);
            expect($cache->get($key))->toBe($before->toArray());

            // A committed availability change forgets the Menu key
            // (Requirements 34.7, 4.6).
            $write->setItemAvailability($restaurantId, $targetItemId, $newValue);
            expect($cache->get($key))->toBeNull();

            // The next read repopulates the key and reflects the new flag
            // (Requirements 4.6, 59.7). The toggled item is present and
            // carries its new availability flag.
            $after = $read->menuFor($restaurantId);

            $toggled = null;
            foreach ($after->categories as $category) {
                foreach ($category->items as $item) {
                    if ($item->id === $targetItemId) {
                        $toggled = $item;
                        break 2;
                    }
                }
            }

            expect($toggled)->not->toBeNull()
                ->and($toggled->isAvailable)->toBe($newValue);

            // The repopulated entry equals the fresh read (Requirement 4.5 across
            // the invalidate-repopulate boundary).
            expect($cache->get($key))->toBe($after->toArray());
        } finally {
            // Commit-based isolation: clear the rows this input committed so the
            // next input starts clean and the afterCommit queue stays drained.
            DB::table('modifier_options')->delete();
            DB::table('modifier_groups')->delete();
            DB::table('menu_items')->delete();
            DB::table('menu_categories')->delete();
            DB::table('restaurants')->delete();
        }
    });
});
