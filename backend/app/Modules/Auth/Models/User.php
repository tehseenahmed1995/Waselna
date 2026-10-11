<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $email
 * @property string $email_lower
 * @property string $password_hash
 * @property string $display_name
 * @property string $role
 * @property string $account_status
 * @property ?string $restaurant_id
 * @property ?string $branch_id
 */
final class User extends Model
{
    use HasUlids;

    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['password_hash'];
}
