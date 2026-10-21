<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Repositories\HoursRepository;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One Branch_Hours interval of a Restaurant_Branch: a single day's half-open
 * `[start_minute, end_minute)` span (Requirements 11.7, 32.5).
 *
 * A Restaurant_Branch's complete Branch_Hours is the set of its rows. Where at
 * least one row exists the Branch_Hours override the Restaurant's Opening_Hours
 * for that Restaurant_Branch; where none exists the Opening_Hours apply
 * (Requirement 32.7). The {@see HoursRepository}
 * is the only place that queries this table, and the hours management service
 * replaces the whole set atomically (Requirement 32.6).
 *
 * @property string $id
 * @property string $restaurant_branch_id
 * @property string $day_of_week
 * @property int $start_minute
 * @property int $end_minute
 */
final class BranchHour extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'branch_hours';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'start_minute' => 'integer',
        'end_minute' => 'integer',
    ];
}
