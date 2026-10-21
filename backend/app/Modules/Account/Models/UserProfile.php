<?php

declare(strict_types=1);

namespace App\Modules\Account\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A Customer's profile row (Requirement 31.1, 31.2).
 *
 * The primary key is the owning `users.id`, so a profile has no identifier of
 * its own. The phone number is stored encrypted at rest through Laravel's
 * `encrypted` cast (Requirement 28.6); `first_name`/`last_name` are retained
 * from the task 4.1 schema and left untouched by this service.
 *
 * @property string $user_id
 * @property ?string $first_name
 * @property ?string $last_name
 * @property ?string $phone_encrypted
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
final class UserProfile extends Model
{
    protected $table = 'user_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Encrypted at rest (Requirement 28.6); decrypted transparently on read.
            'phone_encrypted' => 'encrypted',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
