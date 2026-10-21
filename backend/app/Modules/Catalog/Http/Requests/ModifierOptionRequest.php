<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Modifier_Option create and update validation (Requirement 11.2).
 *
 * A name shorter than 1 or longer than 100 characters, a display-order value
 * outside [0, 9999], or a Price_Delta outside the signed minor-unit range
 * [-99999999, 99999999], is rejected with HTTP 422 and `validation_failed`.
 */
final class ModifierOptionRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'price_delta_minor' => ['required', 'integer', 'between:-99999999,99999999'],
            'display_order' => ['required', 'integer', 'between:0,9999'],
        ];
    }
}
