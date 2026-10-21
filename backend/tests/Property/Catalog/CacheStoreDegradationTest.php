<?php

declare(strict_types=1);

// Feature: waselna, Property 14: A Cache_Store failure degrades to PostgreSQL without failing the request

/*
 * For any cached Menu read executed while the Cache_Store is unreachable, the
 * read model served equals the one produced with a healthy Cache_Store, the
 * request never fails, and the Cache_Store failure is recorded in the
 * Observability_Stack.
 *
 * **Validates: Requirements 21.4**
 *
 * The subject is the Catalog Menu read served through
 * {@see App\Shared\Support\CacheOrFallback} by
 * {@see App\Modules\Catalog\Services\MenuQueryServiceImplementation} (task 8.3).
 * CacheOrFallback is the single wrapper every cached read model travels through,
 * so exercising it on the Menu read exercises the degradation contract shared by
 * every cached read endpoint (the Restaurant search read model of task 9.4 reuses
 * the same wrapper).
 *
 * Each generated input is an arbitrary Menu structure — a published Restaurant
 * with 0..3 Menu_Categories, each with 0..4 Menu_Items, each item available or
 * not, with signed Price_Deltas on a Modifier_Group's options — seeded through
 * the real write-side service so the PostgreSQL source of truth is populated the
 * same way production is. The property then performs two reads of the same
 * Restaurant:
 *
 *  - a *healthy* read through an ArrayStore-backed Cache_Store, which is the
 *    reference response (a cold read builds from PostgreSQL and populates the
 *    entry); and
 *  - a *degraded* read through a Cache_Store that throws on every contact,
 *    standing in for an unreachable Redis.
 *
 * It asserts the single guarantee of Property 14:
 *
 *  - the degraded read returns a value content-identical to the healthy read
 *    (the response equals the healthy-Cache_Store response — Requirement 21.4),
 *    so no cache outage changes what the Customer sees; and
 *  - exactly one `cache.fallback` entry is recorded in the Observability_Stack
 *    naming the Menu key, proving the failure was observed rather than swallowed;
 *    and
 *  - the degraded read never raised — reaching the assertions is itself the
 *    proof that the request did not fail for the cache outage.
 *
 * Database and determinism. The read is value-identical regardless of storage
 * engine, so the property runs on the suite's SQLite in-memory connection
 * (phpunit.xml forces DB_CONNECTION=sqlite and DB_DATABASE=:memory:; a CLI run
 * against the pgsql container must set those explicitly because the container
 * .env defaults to pgsql). The Menu tables are built directly from the task 8.1
 * migration columns — matching the Catalog Menu Feature suite's SQLite approach —
 * rather than by running the PostGIS/btree_gist-bearing migration set SQLite
 * cannot apply. Each generated input runs inside a transaction rolled back
 * afterwards, as the Testing Strategy prescribes for database-touching
 * properties. Identifiers come only from the injected SequenceIdGenerator and
 * instants from a FrozenClock, so seeding is deterministic.
 *
 * All global helpers and the Cache_Store doubles are prefixed `cacheDeg86` so
 * they never collide with the Catalog Menu Feature suite's `menuRead*` helpers
 * (which carry an identically-shaped unreachable-cache double) loaded in the same
 * Pest run.
 */

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
use App\Shared\Support\CacheOrFallback;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\SequenceIdGenerator;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

uses(TestTrait::class);

/**
 * A LoggerInterface that captures the entries written to it, so the property can
 * assert the Cache_Store fallback is recorded in the Observability_Stack
 * (Requirement 21.4) without a live log channel.
 */
final class CacheDeg86SpyLogger extends NullLogger
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}

/**
 * A Cache_Store that throws on every contact, standing in for an unreachable
 * Redis so the CacheOrFallback degradation path is exercised (Requirement 21.4,
 * Property 14). Every method of the Cache contract throws, so neither a `get`, a
 * `put`, nor any other contact can succeed.
 */
final class CacheDeg86UnreachableCache implements CacheContract
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
 * Builds the Menu tables directly from the task 8.1 migration's columns, plus
 * the Restaurant table the Menu read filters on `status = published`. The
 * Postgres-only constraints are deliberately omitted: this property is about the
 * read value and the Cache_Store degradation path, both observable on the
 * portable columns alone.
 */
function cacheDeg86CreateSchema(): void
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
 * A write-side Menu service wired for seeding the stored Menu deterministically
 * (fixed Clock and sequence identifiers; its own ArrayStore invalidator, whose
 * evictions are irrelevant to this property's reads).
 */
function cacheDeg86WriteService(): MenuManagementServiceImplementation
{
    return new MenuManagementServiceImplementation(
        new MenuRepository,
        FrozenClock::at('2025-03-01T12:00:00Z'),
        new SequenceIdGenerator,
        new MenuCacheInvalidator(new CacheRepository(new ArrayStore)),
        new Dispatcher,
    );
}

/**
 * A read-side Menu service over the given Cache_Store and logger, so a healthy
 * read and a degraded read of the same stored Menu can be compared.
 */
function cacheDeg86ReadService(CacheContract $cache, LoggerInterface $logger): MenuQueryServiceImplementation
{
    return new MenuQueryServiceImplementation(
        new MenuReadModelRepository,
        new CacheOrFallback($cache, $logger),
    );
}

function cacheDeg86SeedPublishedRestaurant(): string
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

/**
 * A bounded list of 0..$max elements of `$element`: a count is chosen first, so
 * the sequence length is small and explicit rather than the open-ended growth of
 * `Generators::seq`, whose nested use three levels deep exhausts memory while
 * shrinking. Fixed-size `vector`s keep each of the ≥100 inputs shallow and fast.
 *
 * @template T
 *
 * @param  ErisGenerator<T>  $element
 * @return ErisGenerator<list<T>>
 */
function cacheDeg86BoundedList(int $max, ErisGenerator $element): ErisGenerator
{
    return Generators::bind(
        Generators::choose(0, $max),
        static fn (int $count): ErisGenerator => $count === 0
            ? Generators::constant([])
            : Generators::vector($count, $element),
    );
}

/**
 * One generated Menu shape: a list of 0..3 categories, each a list of 0..4
 * items, each item carrying its availability flag and an optional modifier group
 * of 0..3 options with signed deltas. Names and display-orders are generated so
 * the ordering and availability-preserving projection is varied across inputs,
 * while the structure stays shallow enough to keep each of the ≥100 inputs fast.
 *
 * @return ErisGenerator<list<array{
 *     name: string,
 *     order: int,
 *     items: list<array{
 *         name: string,
 *         order: int,
 *         price: int,
 *         available: bool,
 *         options: list<array{name: string, delta: int}>
 *     }>
 * }>>
 */
function cacheDeg86MenuShape(): ErisGenerator
{
    $name = Generators::map(
        static fn (int $n): string => 'N'.$n,
        Generators::choose(1, 9999),
    );

    $option = Generators::map(
        static fn (array $parts): array => ['name' => $parts[0], 'delta' => $parts[1]],
        Generators::tuple($name, Generators::choose(-500, 500)),
    );

    $item = Generators::map(
        static fn (array $parts): array => [
            'name' => $parts[0],
            'order' => $parts[1],
            'price' => $parts[2],
            'available' => $parts[3],
            'options' => $parts[4],
        ],
        Generators::tuple(
            $name,
            Generators::choose(0, 5),
            Generators::choose(0, 99999999),
            Generators::bool(),
            cacheDeg86BoundedList(2, $option),
        ),
    );

    $category = Generators::map(
        static fn (array $parts): array => [
            'name' => $parts[0],
            'order' => $parts[1],
            'items' => $parts[2],
        ],
        Generators::tuple(
            $name,
            Generators::choose(0, 5),
            cacheDeg86BoundedList(3, $item),
        ),
    );

    return cacheDeg86BoundedList(3, $category);
}

/**
 * Seeds the generated Menu shape through the write-side service so PostgreSQL is
 * populated the production way, and returns the published Restaurant's id.
 *
 * @param  list<array{name: string, order: int, items: list<array{name: string, order: int, price: int, available: bool, options: list<array{name: string, delta: int}>}>}>  $shape
 */
function cacheDeg86Seed(MenuManagementServiceImplementation $write, array $shape): string
{
    $restaurantId = cacheDeg86SeedPublishedRestaurant();

    foreach ($shape as $category) {
        $createdCategory = $write->createCategory(
            new MenuCategoryCommand($restaurantId, $category['name'], $category['order']),
        );

        foreach ($category['items'] as $item) {
            $createdItem = $write->createItem(new MenuItemCommand(
                $restaurantId,
                $createdCategory->id,
                $item['name'],
                null,
                $item['price'],
                $item['available'],
                $item['order'],
            ));

            if ($item['options'] !== []) {
                $group = $write->createGroup(
                    new ModifierGroupCommand($restaurantId, $createdItem->id, 'Extras', 0, 3, 0),
                );

                $optionOrder = 0;
                foreach ($item['options'] as $option) {
                    $write->createOption(new ModifierOptionCommand(
                        $restaurantId,
                        $group->id,
                        $option['name'],
                        $option['delta'],
                        $optionOrder++,
                    ));
                }
            }
        }
    }

    return $restaurantId;
}

beforeEach(function (): void {
    cacheDeg86CreateSchema();
});

it('serves the same Menu read from PostgreSQL and records the failure when the Cache_Store is unreachable', function (): void {
    $this->limitTo(100)->forAll(
        cacheDeg86MenuShape(),
    )->then(function (array $shape): void {
        DB::beginTransaction();

        try {
            $write = cacheDeg86WriteService();
            $restaurantId = cacheDeg86Seed($write, $shape);

            // Reference response: a read through a healthy Cache_Store. The
            // healthy logger must stay silent — a healthy read records no
            // fallback.
            $healthyLogger = new CacheDeg86SpyLogger;
            $healthy = cacheDeg86ReadService(new CacheRepository(new ArrayStore), $healthyLogger)
                ->menuFor($restaurantId);

            // Degraded response: a read through a Cache_Store that throws on
            // every contact. Reaching past this line is itself proof the request
            // did not fail for the cache outage (Requirement 21.4).
            $degradedLogger = new CacheDeg86SpyLogger;
            $degraded = cacheDeg86ReadService(new CacheDeg86UnreachableCache, $degradedLogger)
                ->menuFor($restaurantId);

            // The degraded read equals the healthy-Cache_Store read, so no cache
            // outage changes what the Customer sees (Requirement 21.4).
            expect($degraded)->toBeInstanceOf(MenuReadModel::class)
                ->and($degraded->toArray())->toBe($healthy->toArray());

            // The healthy read recorded no fallback; the degraded read recorded
            // exactly one `cache.fallback` entry naming the Menu key, so the
            // failure was observed in the Observability_Stack rather than
            // swallowed (Requirement 21.4).
            expect($healthyLogger->records)->toBe([])
                ->and($degradedLogger->records)->toHaveCount(1)
                ->and($degradedLogger->records[0]['context']['event'])->toBe('cache.fallback')
                ->and($degradedLogger->records[0]['context']['cache_key'])
                ->toBe(MenuCacheInvalidator::menuKey($restaurantId));
        } finally {
            DB::rollBack();
        }
    });
});
