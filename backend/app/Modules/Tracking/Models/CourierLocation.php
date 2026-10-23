<?php

declare(strict_types=1);

namespace App\Modules\Tracking\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The latest known location of a Courier (one row per courier). Written by the
 * Courier's device and read by the Order tracking view.
 *
 * @property string $id
 * @property string $courier_id
 * @property string $latitude
 * @property string $longitude
 * @property ?\DateTimeInterface $updated_at
 */
final class CourierLocation extends Model
{
    use HasUlids;

    protected $table = 'courier_current_locations';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'updated_at' => 'datetime',
    ];
}
