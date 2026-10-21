<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Product_Variant create and update validation (Requirement 34.1).
 *
 * A name shorter than 1 or longer than 100 characters, or a display-order value
 * outside [0, 9999], is rejected with HTTP 422 and `validation_failed`. The Price
 * floor of 1 minor unit is a business rule the service rejects with
 * `invalid_price` (Requirements 11.3, 34.1).
 */
final class ProductVariantRequest extends FormRequest
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
            'price_minor' => ['required', 'integer'],
            'is_available' => ['sometimes', 'boolean'],
            'display_order' => ['required', 'integer', 'between:0,9999'],
        ];
    }
}
