<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property ?string $assigned_courier_id
 * @property string $status
 * @property array $pickup_location
 * @property array $dropoff_location
 */
final class DeliveryJob extends Model
{
    use HasUlids;

    protected $table = 'delivery_jobs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'pickup_location' => 'array',
        'dropoff_location' => 'array',
        'distance_meters' => 'integer',
        'estimated_minutes' => 'integer',
    ];
}
