<?php

declare(strict_types=1);

namespace App\Modules\Account\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One enabled-or-disabled flag for a Customer, a Notification category, and a
 * channel (Requirement 31.1, 31.2).
 *
 * @property string $id
 * @property string $user_id
 * @property string $category
 * @property string $channel
 * @property bool $enabled
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class NotificationPreference extends Model
{
    use HasUlids;

    protected $table = 'notification_preferences';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
