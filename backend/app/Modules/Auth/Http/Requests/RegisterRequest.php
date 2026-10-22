<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterRequest extends FormRequest
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
            'password' => ['required', 'string', 'min:12', 'max:128'],
            'display_name' => ['required', 'string', 'min:1', 'max:100'],
            // Self-service roles only; administrator and restaurant_staff are not
            // selectable here (staff are invited by their operator).
            'role' => ['sometimes', 'string', 'in:customer,restaurant_operator,courier'],
            // Required by the service when role is restaurant_operator.
            'restaurant_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:120'],
        ];
    }
}
