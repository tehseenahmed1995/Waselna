<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Menu_Category of a Restaurant's Menu (Requirement 11.1).
 *
 * @property string $id
 * @property string $restaurant_id
 * @property string $name
 * @property int $display_order
 * @property ?\DateTimeInterface $archived_at
 */
final class MenuCategory extends Model
{
    use HasUlids;

    protected $table = 'menu_categories';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'display_order' => 'integer',
        'archived_at' => 'datetime',
    ];

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'category_id')->orderBy('display_order');
    }
}
