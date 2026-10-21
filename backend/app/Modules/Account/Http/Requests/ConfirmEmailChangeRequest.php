<?php

declare(strict_types=1);

namespace App\Modules\Account\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Email-change confirmation validation (Requirements 31.12, 31.13).
 *
 * Only the presence of a token is checked here; whether it is recorded, unused,
 * and unexpired is a business rule the service evaluates so the non-disclosing
 * `invalid_confirmation_token` outcome is produced in one place.
 */
final class ConfirmEmailChangeRequest extends FormRequest
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
            'token' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}
