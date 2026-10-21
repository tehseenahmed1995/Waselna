<?php

declare(strict_types=1);

namespace App\Modules\Account\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A Customer's saved Delivery_Address (Requirements 31.7–31.11).
 *
 * The free-form address parts are stored encrypted at rest through Laravel's
 * `encrypted` cast and decrypted transparently on read (Requirement 28.6,
 * design Property 65); the `label`, `country_code`, and coordinate columns are
 * not contact detail and stay in the clear so the list read can sort and the
 * Zone-resolved coordinate stays queryable.
 *
 * Deletion archives rather than removes (Requirement 31.10): `archived_at`
 * carries the instant of archival, so an archived address is excluded from the
 * list yet its snapshot on any referencing Order survives. `created_at` and
 * `updated_at` are written through Eloquent, whose time source is the injected
 * Clock under test.
 *
 * @property string $id
 * @property string $user_id
 * @property string $label
 * @property string $line1_encrypted
 * @property ?string $line2_encrypted
 * @property string $locality_encrypted
 * @property string $postal_code_encrypted
 * @property string $country_code
 * @property string $latitude
 * @property string $longitude
 * @property bool $is_default
 * @property ?Carbon $archived_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class DeliveryAddress extends Model
{
    use HasUlids;

    protected $table = 'delivery_addresses';

    public $incrementing = false;

    protected $keyType = 'string';

    // Timestamps are written explicitly from the injected Clock (through the
    // AddressService), not from Eloquent's wall-clock default, so the created
    // and archived instants stay deterministic under test.
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Encrypted at rest (Requirement 28.6); decrypted transparently on read.
            'line1_encrypted' => 'encrypted',
            'line2_encrypted' => 'encrypted',
            'locality_encrypted' => 'encrypted',
            'postal_code_encrypted' => 'encrypted',
            'is_default' => 'boolean',
            // Decimal cast keeps the coordinate as a fixed-precision string so it
            // round-trips to the Coordinate value object without float drift.
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'archived_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Whether this address is still live (not archived) — the single predicate
     * the list read, the default rules, and the archival guard all turn on
     * (Requirements 31.9, 31.10, 31.11).
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
