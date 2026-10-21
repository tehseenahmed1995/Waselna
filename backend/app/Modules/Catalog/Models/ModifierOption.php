<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A Modifier_Option within a Modifier_Group — the single mechanism for optional
 * extras and add-ons (Requirement 34.6). Its Price_Delta is a signed integer
 * number of minor currency units (Requirement 11.2).
 *
 * @property string $id
 * @property string $modifier_group_id
 * @property string $name
 * @property int $price_delta_minor
 * @property int $display_order
 */
final class ModifierOption extends Model
{
    use HasUlids;

    protected $table = 'modifier_options';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'price_delta_minor' => 'integer',
        'display_order' => 'integer',
    ];
}
