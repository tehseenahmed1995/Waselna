<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one wrapper every cached read model travels through (design "Caching
 * design"; Requirements 21.2, 21.3, 21.4).
 *
 * Given a key, a time-to-live, and a `$build` closure that produces the value
 * from PostgreSQL, it:
 *
 *  - returns the cached value on a hit;
 *  - on a miss, calls `$build`, stores the result under the key with the given
 *    TTL, and returns it (the cold-read repopulation of Requirement 4.8); and
 *  - if the Cache_Store itself is unreachable — a `get`, a `put`, or the hit
 *    decode throws — records the failure in the Observability_Stack and serves
 *    the value from `$build` anyway, so the request never fails for a cache
 *    outage (Requirement 21.4, Property 14).
 *
 * Because the value served on every path is produced by the same `$build`
 * closure, a cached read is content-identical to an uncached read
 * (Requirement 4.5, Property 13).
 *
 * It is a genuine abstraction rather than an inline helper because it has a
 * second use site — the Restaurant search read model of task 9.4 — and because
 * the degradation path must be exercised with a Cache_Store test double that
 * throws on contact. It stores nothing of its own and holds no module rules, so
 * it lives in the shared kernel's support namespace beside {@see Clock} and
 * {@see IdGenerator}.
 */
final readonly class CacheOrFallback
{
    public function __construct(
        private Cache $cache,
        private LoggerInterface $logger,
    ) {}

    /**
     * Serves `$key` from the Cache_Store, falling back to `$build` on a miss
     * (repopulating) or on any Cache_Store error (recording the failure).
     *
     * `$build` is the single source of truth: it is the PostgreSQL projection,
     * and it is called at most once per invocation. `$encode` and `$decode`
     * convert between the built value and the array shape that is actually
     * stored, so the cached entry is a plain, portable payload rather than a
     * serialized object graph.
     *
     * @template TValue
     *
     * @param  string  $key  the fully-qualified Cache_Store key
     * @param  int  $ttlSeconds  the entry lifetime in seconds (300 for read models)
     * @param  Closure(): TValue  $build  builds the value from PostgreSQL
     * @param  Closure(TValue): array<string, mixed>  $encode  value → stored array
     * @param  Closure(array<string, mixed>): TValue  $decode  stored array → value
     * @return TValue
     */
    public function remember(
        string $key,
        int $ttlSeconds,
        Closure $build,
        Closure $encode,
        Closure $decode,
    ): mixed {
        try {
            /** @var array<string, mixed>|null $cached */
            $cached = $this->cache->get($key);

            if ($cached !== null) {
                return $decode($cached);
            }

            $value = $build();
            $this->cache->put($key, $encode($value), $ttlSeconds);

            return $value;
        } catch (Throwable $failure) {
            // The Cache_Store is unreachable. Record it and serve from
            // PostgreSQL rather than failing the request (Requirement 21.4). The
            // key is safe to log; it carries only a Restaurant identifier.
            $this->logger->error('Cache_Store unreachable; served read model from PostgreSQL.', [
                'event' => 'cache.fallback',
                'cache_key' => $key,
                'failure' => $failure::class,
                'message' => $failure->getMessage(),
            ]);

            return $build();
        }
    }
}
