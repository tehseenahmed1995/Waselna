<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\Services\MenuQueryService;
use App\Modules\Catalog\Domain\MenuReadModel;
use App\Modules\Catalog\Repositories\MenuCacheInvalidator;
use App\Modules\Catalog\Repositories\MenuReadModelRepository;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Support\CacheOrFallback;

/**
 * Serves the Customer-facing Menu read model for a published Restaurant through
 * the CacheOrFallback pattern (Requirement 4; Requirements 21.2–21.4).
 *
 * The service holds the rule; {@see MenuReadModelRepository} holds the query and
 * {@see CacheOrFallback} holds the Cache_Store choreography. On a cache hit the
 * stored `menu:v1:{restaurantId}` entry is decoded and returned; on a miss the
 * projection is built from PostgreSQL, stored with a 300-second expiry, and
 * returned (Requirement 4.8); if the Cache_Store is unreachable the projection
 * is served from PostgreSQL and the failure recorded in the Observability_Stack
 * (Requirement 21.4). Every path returns the same repository projection, so a
 * cached read is content-identical to an uncached read (Requirement 4.5).
 *
 * The cache key is the identical one {@see MenuCacheInvalidator} forgets on an
 * availability change (task 8.2), so a committed write is reflected by the next
 * read: the invalidator evicts the key and this service repopulates it on the
 * following miss (Requirement 4.6).
 *
 * The non-disclosing `restaurant_not_found` for an identifier that matches no
 * published Restaurant is raised from inside the build closure (Requirement
 * 4.9); because the throw happens before any `put`, a rejected read caches
 * nothing.
 */
final readonly class MenuQueryServiceImplementation implements MenuQueryService
{
    /** The Menu read-model cache lifetime in seconds (Requirements 4.8, 21.2). */
    public const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private MenuReadModelRepository $repository,
        private CacheOrFallback $cache,
    ) {}

    public function menuFor(string $restaurantId): MenuReadModel
    {
        return $this->cache->remember(
            MenuCacheInvalidator::menuKey($restaurantId),
            self::CACHE_TTL_SECONDS,
            build: fn (): MenuReadModel => $this->buildOrFail($restaurantId),
            encode: static fn (MenuReadModel $menu): array => $menu->toArray(),
            decode: static fn (array $data): MenuReadModel => MenuReadModel::fromArray($data),
        );
    }

    /**
     * Builds the read model from PostgreSQL, raising the non-disclosing 404 where
     * the Restaurant is not published (Requirement 4.9).
     */
    private function buildOrFail(string $restaurantId): MenuReadModel
    {
        $menu = $this->repository->composeForPublished($restaurantId);

        if ($menu === null) {
            throw AuthorizationException::notFound(
                'restaurant_not_found',
                'No matching Restaurant was found.',
                ['restaurant_id' => $restaurantId],
            );
        }

        return $menu;
    }
}
