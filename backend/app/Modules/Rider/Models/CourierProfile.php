<?php

declare(strict_types=1);

namespace App\Modules\Rider\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $user_id
 * @property ?string $phone
 * @property ?string $vehicle_type
 * @property string $rider_status
 */
final class CourierProfile extends Model
{
    protected $table = 'courier_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
