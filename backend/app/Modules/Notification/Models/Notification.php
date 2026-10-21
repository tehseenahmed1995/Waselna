<?php

declare(strict_types=1);

namespace App\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $category
 * @property string $channel
 * @property string $status
 * @property ?string $title
 * @property ?string $body
 */
final class Notification extends Model
{
    use HasUlids;

    protected $table = 'notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    // The notifications table has created_at but no updated_at; manage timestamps manually.
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
    ];
}
