<?php

declare(strict_types=1);

namespace App\Modules\Review\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property string $customer_id
 * @property string $restaurant_id
 * @property int $rating
 * @property ?string $comment
 * @property bool $is_hidden
 */
final class Review extends Model
{
    use HasUlids;

    protected $table = 'reviews';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'rating' => 'integer',
        'is_hidden' => 'boolean',
    ];
}
