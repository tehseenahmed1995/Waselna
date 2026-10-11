<?php

declare(strict_types=1);

namespace App\Modules\Order\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An append-only Order_Timeline entry recording one transition with the acting
 * Platform user and a UTC timestamp (Requirement 10.11).
 *
 * @property string $id
 * @property string $order_id
 * @property string $new_status
 * @property ?string $actor_role
 * @property ?string $actor_id
 * @property ?string $notes
 */
final class OrderTimelineEntry extends Model
{
    use HasUlids;

    protected $table = 'order_timeline_entries';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
