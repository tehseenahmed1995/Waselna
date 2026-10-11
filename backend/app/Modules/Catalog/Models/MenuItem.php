<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $restaurant_id
 * @property string $category_id
 * @property string $name
 * @property ?string $description
 * @property int $price_minor
 * @property bool $is_available
 */
final class MenuItem extends Model
{
    use HasUlids;

    protected $table = 'menu_items';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'price_minor' => 'integer',
        'is_available' => 'boolean',
        'display_order' => 'integer',
    ];
}
