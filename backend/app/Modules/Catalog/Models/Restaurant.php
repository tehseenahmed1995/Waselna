<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property ?string $description
 * @property string $status
 * @property string $currency
 */
final class Restaurant extends Model
{
    use HasUlids;

    protected $table = 'restaurants';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'tax_rate_bp' => 'integer',
        'base_delivery_fee' => 'integer',
        'rating' => 'float',
    ];

    public function categories(): HasMany
    {
        return $this->hasMany(MenuCategory::class, 'restaurant_id')->orderBy('display_order');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'restaurant_id');
    }

    public function cuisineTags(): HasMany
    {
        return $this->hasMany(CuisineTag::class, 'restaurant_id');
    }
}
