<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Pricing\Domain\EligibilityCondition;
use App\Modules\Pricing\Repositories\PromotionRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One `promotion_conditions` row (Requirement 7.6).
 *
 * A Promotion's eligibility conditions, each a kind plus an operand. Internal to
 * the Pricing module; the {@see PromotionRepository}
 * maps a row onto the matching {@see EligibilityCondition}.
 * The operand is stored as JSON so a flag condition carries null and a future
 * richer operand needs no schema change.
 *
 * @property string $id
 * @property string $promotion_id
 * @property string $kind
 * @property int|string|null $operand
 * @property ?Carbon $created_at
 */
final class PromotionCondition extends Model
{
    protected $table = 'promotion_conditions';

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
            'operand' => 'json',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class, 'promotion_id');
    }
}
