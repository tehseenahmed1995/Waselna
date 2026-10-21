<?php

declare(strict_types=1);

// Feature: waselna, Property 12: Menu read models are complete, ordered, and availability-preserving

/*
 * For any generated Menu, the composed read model contains every Menu_Category
 * and every Menu_Item of that Menu — including items whose availability flag is
 * false, which are marked unavailable and fully populated rather than omitted —
 * ordered at both levels by ascending display-order value then ascending name;
 * every Menu_Item carries its name, description, Price as integer minor units
 * with the Restaurant's ISO 4217 currency code at the top, availability flag,
 * and every Modifier_Group with its Modifier_Options and signed Price_Deltas;
 * and every returned Modifier_Group satisfies 0 <= minimum <= maximum <= 50
 * selections. A Restaurant with no Menu_Category, and a Menu_Category with no
 * Menu_Item, is carried with an empty collection rather than dropped.
 *
 * **Validates: Requirements 4.1, 4.2, 4.3, 4.4, 4.7**
 *
 * The subject is the Catalog Menu read model of task 8.3:
 * MenuReadModelRepository composes the projection from the stored Menu tables
 * and MenuQueryServiceImplementation serves it through CacheOrFallback. This
 * property drives the read model directly over seeded storage so the generated
 * Menu — categories, items, modifier groups, and options with arbitrary
 * display-order and name ties, a mix of available and unavailable items, and
 * empty categories or an empty Menu — is projected faithfully. An independent
 * in-memory oracle sorts the seeded rows by (display_order, name) and the
 * property asserts the composed value equals the oracle at every level.
 *
 * Database isolation follows the Testing Strategy: each generated input runs
 * inside a transaction rolled back before the next, so one seeded Restaurant
 * per input leaves no residue. The schema is built directly from the columns
 * the read model reads rather than by running the full migration set, because
 * the Property suite's SQLite database cannot evaluate the PostGIS statements
 * later Catalog migrations carry. An ArrayStore Cache_Store owned by each input
 * means the Service's warm/cold paths observe only what this test populated.
 *
 * Helpers are prefixed `menuRM84` so they never collide with the task 8.3
 * example suite's `menuRead*` global helpers loaded in the same Pest run.
 */

use App\Modules\Catalog\Domain\MenuReadModel;
use App\Modules\Catalog\Repositories\MenuReadModelRepository;
use App\Modules\Catalog\Services\MenuQueryServiceImplementation;
use App\Shared\Support\CacheOrFallback;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psr\Log\NullLogger;

uses(TestTrait::class);

/**
 * The ISO 4217 currency codes the generated Restaurant draws from, so the
 * top-level currency assertion (Requirement 4.2) is exercised against more than
 * one value.
 */
const MENU_RM84_CURRENCIES = ['USD', 'EUR', 'GBP', 'JPY', 'SAR'];

/**
 * Builds the six Catalog tables the Menu read model reads, directly from the
 * columns of the task 8.1 migrations. Only the columns the repository selects
 * or orders by are created; the PostGIS-bearing Catalog migrations are
 * deliberately not run so the suite stays on its SQLite database. Mirrors the
 * task 8.3 example suite's schema so the projection sees identical storage.
 */
function menuRM84CreateSchema(): void
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

/**
 * A short name drawn from a tiny alphabet so display-order ties are frequently
 * broken by name, exercising the two-key ordering of Requirement 4.1. The small
 * alphabet makes equal and near-equal names common, and the characters survive a
 * database round trip unchanged so a stored name compares equal to the oracle.
 *
 * @return ErisGenerator<string>
 */
function menuRM84Name(): ErisGenerator
{
    return Generators::map(
        static fn (array $letters): string => implode('', $letters),
        Generators::vector(2, Generators::elements(['A', 'B', 'C', 'd', 'e'])),
    );
}

/**
 * A list of 0..$maximum elements drawn from $element. A count is chosen first
 * and then exactly that many elements are generated into a fixed-size vector, so
 * the generated structure is bounded by $maximum regardless of the runner's
 * generation size — unlike `Generators::seq`, whose length grows with the size
 * and, nested four levels deep, exhausts memory. The empty list is reachable so
 * the empty-Menu and empty-category cases of Requirement 4.7 are covered by
 * construction.
 *
 * @return ErisGenerator<list<mixed>>
 */
function menuRM84BoundedList(int $maximum, ErisGenerator $element): ErisGenerator
{
    return Generators::bind(
        Generators::choose(0, $maximum),
        static function (int $count) use ($element): ErisGenerator {
            if ($count === 0) {
                return Generators::constant([]);
            }

            return Generators::tuple(...array_fill(0, $count, $element));
        },
    );
}

/**
 * A single node — category, item, group, or option — as a (display_order, name)
 * pair. Display-order is drawn from a tiny range so ties with other siblings
 * are the common case rather than a rare one.
 *
 * @return ErisGenerator<array{display_order: int, name: string}>
 */
function menuRM84Node(): ErisGenerator
{
    return Generators::associative([
        'display_order' => Generators::choose(0, 2),
        'name' => menuRM84Name(),
    ]);
}

/**
 * A Modifier_Group with its ordered selection bounds and its Modifier_Options.
 * The bounds are drawn so 0 <= min <= max <= 50 holds by construction
 * (Requirement 4.3): `min` in 0..50 and `max` the sum of `min` and a slack in
 * 0..(50 - min). Options carry signed Price_Deltas (Requirement 4.2) spanning
 * negative, zero, and positive minor units. Zero options is permitted.
 *
 * @return ErisGenerator<array{node: array{display_order: int, name: string}, min: int, max: int, options: list<array{node: array{display_order: int, name: string}, delta: int}>}>
 */
function menuRM84Group(): ErisGenerator
{
    return Generators::associative([
        'node' => menuRM84Node(),
        'min' => Generators::choose(0, 50),
        'slack' => Generators::choose(0, 50),
        'options' => menuRM84BoundedList(
            3,
            Generators::associative([
                'node' => menuRM84Node(),
                'delta' => Generators::choose(-500, 500),
            ]),
        ),
    ]);
}

/**
 * A Menu_Item carrying its ordering node, a signed Price in minor units, an
 * availability flag drawn so unavailable items are common (Requirement 4.4), a
 * nullable description, and its Modifier_Groups. Zero groups is permitted.
 *
 * @return ErisGenerator<array{node: array{display_order: int, name: string}, price: int, available: bool, description: ?string, groups: list<mixed>}>
 */
function menuRM84Item(): ErisGenerator
{
    return Generators::associative([
        'node' => menuRM84Node(),
        'price' => Generators::choose(0, 99999999),
        'available' => Generators::bool(),
        'description' => Generators::oneOf(Generators::constant(null), menuRM84Name()),
        'groups' => menuRM84BoundedList(2, menuRM84Group()),
    ]);
}

/**
 * A Menu_Category carrying its ordering node and its Menu_Items. Zero items is
 * permitted so the empty-category case of Requirement 4.7 is reached by
 * construction.
 *
 * @return ErisGenerator<array{node: array{display_order: int, name: string}, items: list<mixed>}>
 */
function menuRM84Category(): ErisGenerator
{
    return Generators::associative([
        'node' => menuRM84Node(),
        'items' => menuRM84BoundedList(3, menuRM84Item()),
    ]);
}

/**
 * A whole generated Menu: a currency and a list of categories (possibly empty,
 * so the empty-Menu case of Requirement 4.7 is reached by construction).
 *
 * @return ErisGenerator<array{currency: string, categories: list<mixed>}>
 */
function menuRM84Menu(): ErisGenerator
{
    return Generators::associative([
        'currency' => Generators::elements(MENU_RM84_CURRENCIES),
        'categories' => menuRM84BoundedList(3, menuRM84Category()),
    ]);
}

/**
 * Makes the names of a sibling list unique by suffixing each with its position,
 * so no two siblings share an identical (display_order, name) key. SQL leaves
 * the relative order of two byte-identical sort keys undefined, which would make
 * an index-by-index comparison against an oracle ambiguous; a disambiguating
 * suffix removes that ambiguity while preserving the common display-order tie
 * that exercises the ascending-name tiebreak of Requirement 4.1. The suffix is
 * from the same small-byte alphabet as the names so it never reorders a pair
 * whose base names already differ.
 *
 * @param  list<array{node: array{display_order: int, name: string}}>  $nodes
 * @return list<array{node: array{display_order: int, name: string}}>
 */
function menuRM84Disambiguate(array $nodes): array
{
    foreach ($nodes as $index => $node) {
        $nodes[$index]['node']['name'] = $node['node']['name'].'-'.sprintf('%03d', $index);
    }

    return $nodes;
}

/**
 * Applies {@see menuRM84Disambiguate} to every sibling list in the generated
 * Menu — categories, items, groups, options — so the whole tree carries
 * distinct names within each parent before it is seeded and compared.
 */
function menuRM84Normalise(array $menu): array
{
    $menu['categories'] = menuRM84Disambiguate($menu['categories']);

    foreach ($menu['categories'] as $catIndex => $category) {
        $items = menuRM84Disambiguate($category['items']);

        foreach ($items as $itemIndex => $item) {
            $groups = menuRM84Disambiguate($item['groups']);

            foreach ($groups as $groupIndex => $group) {
                $groups[$groupIndex]['options'] = menuRM84Disambiguate($group['options']);
            }

            $items[$itemIndex]['groups'] = $groups;
        }

        $menu['categories'][$catIndex]['items'] = $items;
    }

    return $menu;
}

/**
 * Seeds the generated Menu into the stored tables and returns the Restaurant
 * identifier. Insertion order is deliberately NOT the expected read order:
 * siblings are shuffled before insert so the repository's ORDER BY is doing the
 * work, not the natural row order.
 */
function menuRM84Seed(array $menu): string
{
    $restaurantId = (string) Str::ulid();
    DB::table('restaurants')->insert([
        'id' => $restaurantId,
        'name' => 'R-'.Str::lower(Str::random(6)),
        'status' => 'published',
        'currency' => $menu['currency'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $categories = $menu['categories'];
    shuffle($categories);

    foreach ($categories as $category) {
        $categoryId = (string) Str::ulid();
        DB::table('menu_categories')->insert([
            'id' => $categoryId,
            'restaurant_id' => $restaurantId,
            'name' => $category['node']['name'],
            'display_order' => $category['node']['display_order'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = $category['items'];
        shuffle($items);

        foreach ($items as $item) {
            $itemId = (string) Str::ulid();
            DB::table('menu_items')->insert([
                'id' => $itemId,
                'restaurant_id' => $restaurantId,
                'category_id' => $categoryId,
                'name' => $item['node']['name'],
                'description' => $item['description'],
                'price_minor' => $item['price'],
                'is_available' => $item['available'],
                'display_order' => $item['node']['display_order'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $groups = $item['groups'];
            shuffle($groups);

            foreach ($groups as $group) {
                $groupId = (string) Str::ulid();
                DB::table('modifier_groups')->insert([
                    'id' => $groupId,
                    'menu_item_id' => $itemId,
                    'name' => $group['node']['name'],
                    'min_selections' => $group['min'],
                    'max_selections' => min(50, $group['min'] + $group['slack']),
                    'display_order' => $group['node']['display_order'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $options = $group['options'];
                shuffle($options);

                foreach ($options as $option) {
                    DB::table('modifier_options')->insert([
                        'id' => (string) Str::ulid(),
                        'modifier_group_id' => $groupId,
                        'name' => $option['node']['name'],
                        'price_delta_minor' => $option['delta'],
                        'display_order' => $option['node']['display_order'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    return $restaurantId;
}

/**
 * The independent oracle: sorts a list of generated nodes by ascending
 * display-order then ascending name, the exact order Requirement 4.1 demands,
 * using a stable comparison that mirrors SQL's byte-wise text ordering. Returns
 * the sorted list so a caller can line it up against the composed projection.
 *
 * @param  list<array{node: array{display_order: int, name: string}}>  $nodes
 * @return list<array{node: array{display_order: int, name: string}}>
 */
function menuRM84Sorted(array $nodes): array
{
    usort($nodes, static function (array $a, array $b): int {
        return [$a['node']['display_order'], $a['node']['name']]
            <=> [$b['node']['display_order'], $b['node']['name']];
    });

    return $nodes;
}

/**
 * The read model under test, wired over a cold ArrayStore Cache_Store and a
 * silent logger. A cold read builds from storage and caches; this property only
 * reads once per input, so the value under assertion is the PostgreSQL-backed
 * projection served through the Service.
 */
function menuRM84ReadModel(): MenuQueryServiceImplementation
{
    return new MenuQueryServiceImplementation(
        new MenuReadModelRepository,
        new CacheOrFallback(new CacheRepository(new ArrayStore), new NullLogger),
    );
}

beforeEach(function (): void {
    menuRM84CreateSchema();
});

it('composes a complete, ordered, availability-preserving Menu for every generated input', function (): void {
    $read = menuRM84ReadModel();

    $this->limitTo(100)
        ->forAll(menuRM84Menu())
        ->then(function (array $menu) use ($read): void {
            // Each input is self-contained: seed, compose, assert, then roll
            // back so the next input starts from empty Catalog tables.
            DB::beginTransaction();

            try {
                // Give every sibling a distinct name so the index-by-index
                // comparison against the oracle is unambiguous while the
                // frequent display-order ties still drive the name tiebreak.
                $menu = menuRM84Normalise($menu);

                $restaurantId = menuRM84Seed($menu);

                $composed = $read->menuFor($restaurantId);

                // The currency sits once at the top (Requirement 4.2).
                expect($composed)->toBeInstanceOf(MenuReadModel::class)
                    ->and($composed->restaurantId)->toBe($restaurantId)
                    ->and($composed->currency)->toBe($menu['currency']);

                // Every Menu_Category is present, ordered by (display_order,
                // name); an empty Menu yields an empty collection, not an error
                // (Requirements 4.1, 4.7).
                $expectedCategories = menuRM84Sorted($menu['categories']);

                expect($composed->categories)->toHaveCount(count($expectedCategories));

                foreach ($expectedCategories as $catIndex => $expectedCategory) {
                    $composedCategory = $composed->categories[$catIndex];

                    expect($composedCategory->name)->toBe($expectedCategory['node']['name'])
                        ->and($composedCategory->displayOrder)->toBe($expectedCategory['node']['display_order']);

                    // Every Menu_Item is present, ordered the same way, with an
                    // empty category carried as an empty item collection
                    // (Requirements 4.1, 4.4, 4.7).
                    $expectedItems = menuRM84Sorted($expectedCategory['items']);

                    expect($composedCategory->items)->toHaveCount(count($expectedItems));

                    foreach ($expectedItems as $itemIndex => $expectedItem) {
                        $composedItem = $composedCategory->items[$itemIndex];

                        // Full payload including an unavailable item carried in
                        // full rather than omitted (Requirements 4.2, 4.4).
                        expect($composedItem->name)->toBe($expectedItem['node']['name'])
                            ->and($composedItem->displayOrder)->toBe($expectedItem['node']['display_order'])
                            ->and($composedItem->description)->toBe($expectedItem['description'])
                            ->and($composedItem->priceMinor)->toBe($expectedItem['price'])
                            ->and($composedItem->isAvailable)->toBe($expectedItem['available']);

                        // Every Modifier_Group is present and ordered, each
                        // satisfying 0 <= min <= max <= 50 (Requirement 4.3).
                        $expectedGroups = menuRM84Sorted($expectedItem['groups']);

                        expect($composedItem->modifierGroups)->toHaveCount(count($expectedGroups));

                        foreach ($expectedGroups as $groupIndex => $expectedGroup) {
                            $composedGroup = $composedItem->modifierGroups[$groupIndex];
                            $expectedMax = min(50, $expectedGroup['min'] + $expectedGroup['slack']);

                            expect($composedGroup->name)->toBe($expectedGroup['node']['name'])
                                ->and($composedGroup->displayOrder)->toBe($expectedGroup['node']['display_order'])
                                ->and($composedGroup->minSelections)->toBe($expectedGroup['min'])
                                ->and($composedGroup->maxSelections)->toBe($expectedMax);

                            expect($composedGroup->minSelections)->toBeGreaterThanOrEqual(0)
                                ->and($composedGroup->minSelections)->toBeLessThanOrEqual($composedGroup->maxSelections)
                                ->and($composedGroup->maxSelections)->toBeLessThanOrEqual(50);

                            // Every Modifier_Option is present and ordered, each
                            // carrying its signed Price_Delta (Requirement 4.2).
                            $expectedOptions = menuRM84Sorted($expectedGroup['options']);

                            expect($composedGroup->options)->toHaveCount(count($expectedOptions));

                            foreach ($expectedOptions as $optionIndex => $expectedOption) {
                                $composedOption = $composedGroup->options[$optionIndex];

                                expect($composedOption->name)->toBe($expectedOption['node']['name'])
                                    ->and($composedOption->displayOrder)->toBe($expectedOption['node']['display_order'])
                                    ->and($composedOption->priceDeltaMinor)->toBe($expectedOption['delta']);
                            }
                        }
                    }
                }
            } finally {
                DB::rollBack();
            }
        });
});
