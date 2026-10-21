<?php

declare(strict_types=1);

namespace App\Modules\Account\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Email-change request validation (Requirements 31.4, 31.3).
 */
final class RequestEmailChangeRequest extends FormRequest
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
        ];
    }
}
