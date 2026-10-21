<?php

declare(strict_types=1);

namespace App\Modules\Account\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Password-change validation (Requirements 31.5, 31.6).
 *
 * The new password range is validated here; a current-password mismatch is a
 * business rule the Auth module evaluates against the stored hash, so it is not
 * expressed as a validation rule.
 */
final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:12', 'max:128'],
        ];
    }
}
