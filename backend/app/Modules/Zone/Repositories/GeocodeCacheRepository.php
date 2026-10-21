<?php

declare(strict_types=1);

namespace App\Modules\Zone\Repositories;

use App\Modules\Zone\Models\GeocodeCache;
use App\Shared\Domain\Coordinate;
use App\Shared\Support\Clock;
use App\Shared\Support\IdGenerator;

/**
 * The one place that queries the `geocode_cache` table (Backend_Layer_Chain,
 * task 6.7). A concrete, Eloquent-backed repository class with no per-repository
 * interface, matching the design's deviation from the Repository_Interface
 * split; the local AddressResolver adapter depends on this class through
 * constructor injection.
 *
 * A row is keyed by the normalized address hash the AddressNormalizer derives,
 * so a repeat submission of the same place reads the stored coordinate instead
 * of resolving again (design Zone notes). The `resolved_at` instant is read from
 * the injected Clock so the write stays deterministic under test (Requirement
 * 40.7).
 */
final class GeocodeCacheRepository
{
    public function __construct(
        private readonly IdGenerator $idGenerator,
        private readonly Clock $clock,
    ) {}

    /**
     * The cached coordinate for a normalized address hash, or null when the
     * address has not been resolved before.
     */
    public function find(string $addressHash): ?Coordinate
    {
        $row = GeocodeCache::query()->where('address_hash', $addressHash)->first();

        if ($row === null) {
            return null;
        }

        return Coordinate::of((string) $row->latitude, (string) $row->longitude);
    }

    /**
     * Records the coordinate a normalized address resolved to. The unique
     * `address_hash` constraint means a concurrent writer that already inserted
     * the same hash wins; this method keeps the existing row rather than raising,
     * so resolution stays idempotent per address.
     */
    public function store(string $addressHash, Coordinate $coordinate): void
    {
        GeocodeCache::query()->firstOrCreate(
            ['address_hash' => $addressHash],
            [
                'id' => $this->idGenerator->ulid(),
                'latitude' => $coordinate->latitude,
                'longitude' => $coordinate->longitude,
                'resolved_at' => $this->clock->nowUtc(),
            ],
        );
    }
}
