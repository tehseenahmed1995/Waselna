<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Menu_Category create and update validation (Requirement 11.9).
 *
 * A name shorter than 1 or longer than 100 characters, or a display-order value
 * outside [0, 9999], is rejected with HTTP 422 and `validation_failed` carrying
 * one detail per violating field. Business rules beyond the schema live in the
 * service.
 */
final class MenuCategoryRequest extends FormRequest
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
            'display_order' => ['required', 'integer', 'between:0,9999'],
        ];
    }
}
