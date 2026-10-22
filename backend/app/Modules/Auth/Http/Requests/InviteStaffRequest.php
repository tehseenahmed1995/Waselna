<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a Restaurant_Operator's request to invite a Restaurant_Staff member.
 * The new staff user's email, display name, and an initial password are required;
 * the restaurant and branch linkage come from the acting operator, not the body.
 */
final class InviteStaffRequest extends FormRequest
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
            'email' => ['required', 'string', 'email', 'max:254'],
            'display_name' => ['required', 'string', 'min:1', 'max:100'],
            'password' => ['required', 'string', 'min:12', 'max:128'],
        ];
    }
}
