<?php

declare(strict_types=1);

namespace App\Modules\Zone\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A Delivery_Zone owned by the Zone module (task 6.5; Requirements 5.8, 32.11).
 *
 * The row stores the authoritative canonical GeoJSON in `geometry_geojson` and
 * the derived, indexed PostGIS geometry in `geom`. `geom` is written and read
 * through PostGIS SQL functions (`ST_GeomFromGeoJSON`, `ST_Covers`) in the
 * repository rather than as a plain Eloquent attribute, so this model exposes
 * only the stable columns.
 *
 * The model carries no relationship to a Catalog model: a Restaurant_Branch is
 * referenced by identifier only, which keeps the Zone module free of a direct
 * dependency on another module's Eloquent_Model (Requirement 22.8).
 *
 * @property string $id
 * @property string $restaurant_id
 * @property string $name
 * @property string $geometry_geojson
 */
final class DeliveryZone extends Model
{
    use HasUlids;

    protected $table = 'delivery_zones';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * `geom` is never selected into the model; it is maintained by PostGIS SQL
     * in the repository. Guarding it keeps a stray mass-assignment from writing
     * a malformed value that would bypass the ST_IsValid check path.
     *
     * @var list<string>
     */
    protected $hidden = ['geom'];
}
