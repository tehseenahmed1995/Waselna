<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Menu_Item of a Menu_Category (Requirement 11.2). Deletion archives the row
 * rather than removing it, so Order history keeps referring to it
 * (Requirement 11.6).
 *
 * @property string $id
 * @property string $restaurant_id
 * @property string $category_id
 * @property string $name
 * @property ?string $description
 * @property ?string $image_url
 * @property int $price_minor
 * @property bool $is_available
 * @property int $display_order
 * @property ?\DateTimeInterface $archived_at
 */
final class MenuItem extends Model
{
    use HasUlids;

    protected $table = 'menu_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'price_minor' => 'integer',
        'is_available' => 'boolean',
        'display_order' => 'integer',
        'archived_at' => 'datetime',
    ];

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'menu_item_id')->orderBy('display_order');
    }

    /**
     * @return HasMany<ModifierGroup, $this>
     */
    public function modifierGroups(): HasMany
    {
        return $this->hasMany(ModifierGroup::class, 'menu_item_id')->orderBy('display_order');
    }
}
