<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $restaurant_id
 * @property string $name
 * @property string $address
 * @property ?float $latitude
 * @property ?float $longitude
 * @property bool $is_active
 */
final class RestaurantBranch extends Model
{
    use HasUlids;

    protected $table = 'restaurant_branches';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'is_active' => 'boolean',
    ];
}
