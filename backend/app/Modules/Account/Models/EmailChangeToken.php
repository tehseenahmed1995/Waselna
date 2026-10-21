<?php

declare(strict_types=1);

namespace App\Modules\Account\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single pending email-change confirmation token for a Customer
 * (Requirements 31.4, 31.12, 31.13).
 *
 * Only the SHA-256 hash of the token is stored; the plaintext leaves the
 * process once, in the issuing response. `used_at` records single use so a
 * confirmed or superseded token can never be replayed.
 *
 * @property string $id
 * @property string $user_id
 * @property string $new_email
 * @property string $new_email_lower
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property ?Carbon $used_at
 * @property ?Carbon $created_at
 */
final class EmailChangeToken extends Model
{
    use HasUlids;

    protected $table = 'email_change_tokens';

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
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
