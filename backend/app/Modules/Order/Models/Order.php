<?php

declare(strict_types=1);

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $customer_id
 * @property string $restaurant_id
 * @property string $restaurant_branch_id
 * @property string $order_number
 * @property string $status
 * @property int $line_subtotal_minor
 * @property int $delivery_fee_minor
 * @property int $service_fee_minor
 * @property int $discount_amount_minor
 * @property int $tax_amount_minor
 * @property int $total_amount_minor
 * @property string $currency
 */
final class Order extends Model
{
    use HasUlids;

    protected $table = 'orders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'line_subtotal_minor' => 'integer',
        'delivery_fee_minor' => 'integer',
        'service_fee_minor' => 'integer',
        'discount_amount_minor' => 'integer',
        'tax_amount_minor' => 'integer',
        'total_amount_minor' => 'integer',
        'delivery_address_snapshot' => 'array',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class, 'order_id');
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(OrderTimelineEntry::class, 'order_id')->orderBy('created_at');
    }
}
