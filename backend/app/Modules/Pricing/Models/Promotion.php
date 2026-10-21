<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Pricing\Domain\PromotionView;
use App\Modules\Pricing\Repositories\PromotionRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The `promotions` row (Requirements 7.5–7.7, 18.4).
 *
 * Internal to the Pricing module and never leaves it: the
 * {@see PromotionRepository} rehydrates the framework-free
 * {@see \App\Modules\Pricing\Domain\Promotion} aggregate from this model, and
 * every consumer sees the aggregate or a {@see PromotionView},
 * not this Eloquent object, so no module couples to the `promotions` schema.
 *
 * `created_at` and `updated_at` are written explicitly from the injected Clock
 * through the service, not Eloquent's wall-clock default, so a created
 * Promotion is deterministic under test.
 *
 * @property string $id
 * @property string $code
 * @property ?string $restaurant_id
 * @property string $discount_kind
 * @property int $discount_value
 * @property Carbon $activates_at
 * @property Carbon $expires_at
 * @property bool $is_active
 * @property ?int $max_uses
 * @property int $times_used
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class Promotion extends Model
{
    protected $table = 'promotions';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_value' => 'integer',
            'max_uses' => 'integer',
            'times_used' => 'integer',
            'is_active' => 'boolean',
            'activates_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PromotionCondition, $this>
     */
    public function conditions(): HasMany
    {
        return $this->hasMany(PromotionCondition::class, 'promotion_id');
    }
}
