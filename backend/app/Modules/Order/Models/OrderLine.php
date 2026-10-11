<?php

declare(strict_types=1);

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property string $menu_item_id
 * @property ?string $product_variant_id
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $subtotal_minor
 * @property string $currency
 */
final class OrderLine extends Model
{
    use HasUlids;

    protected $table = 'order_lines';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price_minor' => 'integer',
        'subtotal_minor' => 'integer',
        'menu_item_snapshot' => 'array',
        'variant_snapshot' => 'array',
    ];
}
