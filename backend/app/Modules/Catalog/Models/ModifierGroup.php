<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Modifier_Group attached to a Menu_Item (Requirements 11.2, 11.4). The
 * selection counts are bounded to [0,50] minimum and [1,50] maximum with
 * minimum <= maximum (Requirement 34.3).
 *
 * @property string $id
 * @property string $menu_item_id
 * @property string $name
 * @property int $min_selections
 * @property int $max_selections
 * @property int $display_order
 */
final class ModifierGroup extends Model
{
    use HasUlids;

    protected $table = 'modifier_groups';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'min_selections' => 'integer',
        'max_selections' => 'integer',
        'display_order' => 'integer',
    ];

    /**
     * @return HasMany<ModifierOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class, 'modifier_group_id')->orderBy('display_order');
    }
}
