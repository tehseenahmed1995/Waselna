<?php

declare(strict_types=1);

namespace App\Modules\Admin\Repositories;

use App\Modules\Admin\Models\PlatformConfiguration;
use App\Modules\Admin\Services\PlatformConfigurationServiceImplementation;
use Illuminate\Support\Facades\DB;

/**
 * The one place that queries the single `platform_configuration` row
 * (Requirements 37.9, 37.10).
 *
 * Concrete and Eloquent-backed, following the repository deviation the other
 * modules document: the {@see PlatformConfigurationServiceImplementation}
 * holds the rules, this holds the SQL. The table carries single-row
 * configuration semantics, so {@see current()} returns the first (and only) row
 * or null when none has been written yet, and {@see upsert()} writes that one
 * row, locking it for the duration of the surrounding transaction so a
 * concurrent Administrator update cannot be lost.
 */
final class PlatformConfigurationRepository
{
    /**
     * The single stored configuration row, or null when the table is empty. The
     * service turns a null into the Platform defaults
     * (design "single-row configuration semantics with sensible defaults").
     */
    public function current(): ?PlatformConfiguration
    {
        /** @var PlatformConfiguration|null $row */
        $row = PlatformConfiguration::query()
            ->orderBy('id')
            ->first();

        return $row;
    }

    /**
     * The single stored configuration row locked for update, or null when the
     * table is empty. Called inside the update transaction so the read-modify-
     * write of the one row is serialised against a concurrent writer.
     */
    public function currentForUpdate(): ?PlatformConfiguration
    {
        /** @var PlatformConfiguration|null $row */
        $row = PlatformConfiguration::query()
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        return $row;
    }

    /**
     * Persists the submitted values into the single configuration row, creating
     * it with the given identifier when none exists yet and updating it in place
     * otherwise, and returns the stored row. Runs inside the transaction opened
     * by the caller.
     *
     * @param  array<string, mixed>  $attributes  the column => value map, already
     *                                            validated and shaped by the service
     */
    public function upsert(string $newId, array $attributes): PlatformConfiguration
    {
        $row = $this->currentForUpdate();

        if ($row === null) {
            $row = new PlatformConfiguration;
            $row->id = $newId;
        }

        $row->fill($attributes);
        $row->save();

        return $row;
    }

    /**
     * Deletes every configuration row. Exists only so a test can assert the
     * reader's empty-table fallback; the production write path never deletes.
     */
    public function truncate(): void
    {
        DB::table('platform_configuration')->delete();
    }
}
