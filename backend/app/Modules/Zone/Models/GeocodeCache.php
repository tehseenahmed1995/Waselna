<?php

declare(strict_types=1);

namespace App\Modules\Zone\Models;

use App\Modules\Zone\Domain\AddressNormalizer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A cached address resolution owned by the Zone module (task 6.7; design Zone
 * notes, Requirements 5.6, 23.8, 43.7).
 *
 * One row records the coordinate a normalized address resolved to, keyed by the
 * SHA-256 `address_hash` the {@see AddressNormalizer}
 * derives. The unique constraint on `address_hash` keeps a repeat submission of
 * the same place from storing a second row, so the local deterministic adapter
 * does its work once per distinct address.
 *
 * `latitude` and `longitude` are stored as fixed-precision decimals at 7
 * fractional digits (Requirement 40.7), matching the Coordinate the adapter
 * returns.
 *
 * @property string $id
 * @property string $address_hash
 * @property string $latitude
 * @property string $longitude
 * @property Carbon $resolved_at
 */
final class GeocodeCache extends Model
{
    use HasUlids;

    protected $table = 'geocode_cache';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'resolved_at' => 'immutable_datetime',
    ];
}
