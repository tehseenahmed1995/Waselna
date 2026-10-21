<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Repositories\HoursRepository;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One Opening_Hours interval of a Restaurant: a single day's half-open
 * `[start_minute, end_minute)` span (Requirements 11.7, 32.5).
 *
 * A Restaurant's complete Opening_Hours is the set of its rows, grouped by
 * `day_of_week`; a day with no row is closed. The {@see HoursRepository}
 * is the only place that queries this table, and the hours management service
 * replaces the whole set atomically rather than editing individual rows
 * (Requirement 32.6).
 *
 * @property string $id
 * @property string $restaurant_id
 * @property string $day_of_week
 * @property int $start_minute
 * @property int $end_minute
 */
final class OpeningHour extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'opening_hours';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'start_minute' => 'integer',
        'end_minute' => 'integer',
    ];
}
