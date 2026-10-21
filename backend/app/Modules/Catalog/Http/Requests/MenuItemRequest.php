<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Menu_Item create and update validation (Requirements 11.2, 11.9).
 *
 * A name shorter than 1 or longer than 100 characters, a description longer than
 * 2,000 characters, or a display-order value outside [0, 9999], is rejected with
 * HTTP 422 and `validation_failed`. `price_minor` must be an integer at the
 * schema level; its [1, 99999999] range is a business rule the service rejects
 * with `invalid_price` (Requirement 11.3), so it is not clamped here.
 */
final class MenuItemRequest extends FormRequest
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
            'category_id' => ['required', 'string', 'min:1'],
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price_minor' => ['required', 'integer'],
            'is_available' => ['sometimes', 'boolean'],
            'display_order' => ['required', 'integer', 'between:0,9999'],
        ];
    }
}
