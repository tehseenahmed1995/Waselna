<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $restaurant_id
 * @property string $name
 * @property string $address
 * @property ?float $latitude
 * @property ?float $longitude
 * @property ?string $time_zone
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

    /**
     * @return HasMany<BranchHour, $this>
     */
    public function branchHours(): HasMany
    {
        return $this->hasMany(BranchHour::class, 'restaurant_branch_id');
    }
}
