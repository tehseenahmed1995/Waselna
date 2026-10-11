<?php

declare(strict_types=1);

namespace App\Modules\Payment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property string $gateway
 * @property ?string $gateway_transaction_id
 */
final class PaymentIntent extends Model
{
    use HasUlids;

    protected $table = 'payment_intents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'amount_minor' => 'integer',
        'gateway_response' => 'array',
    ];
}
