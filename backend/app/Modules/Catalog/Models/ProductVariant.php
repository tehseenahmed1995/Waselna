<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A sized or named Product_Variant of a Menu_Item (Requirement 34.1). Where a
 * Menu_Item holds at least one Product_Variant, the selected variant's Price
 * replaces the Menu_Item Price as the Cart_Line base Price (Requirement 34.2).
 *
 * @property string $id
 * @property string $menu_item_id
 * @property string $name
 * @property int $price_minor
 * @property bool $is_available
 * @property int $display_order
 */
final class ProductVariant extends Model
{
    use HasUlids;

    protected $table = 'product_variants';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'price_minor' => 'integer',
        'is_available' => 'boolean',
        'display_order' => 'integer',
    ];
}
