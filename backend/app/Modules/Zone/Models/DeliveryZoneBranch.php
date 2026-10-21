<?php

declare(strict_types=1);

namespace App\Modules\Zone\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The assignment of a Delivery_Zone to a Restaurant_Branch (task 6.5;
 * Requirement 32.11).
 *
 * One row links one zone to one branch; the unique constraint on
 * (`delivery_zone_id`, `restaurant_branch_id`) keeps the pair from being
 * recorded twice. A Restaurant_Branch is referenced by identifier only, so the
 * Zone module holds no direct dependency on a Catalog model (Requirement 22.8).
 *
 * @property string $id
 * @property string $delivery_zone_id
 * @property string $restaurant_branch_id
 */
final class DeliveryZoneBranch extends Model
{
    use HasUlids;

    protected $table = 'delivery_zone_branch';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
