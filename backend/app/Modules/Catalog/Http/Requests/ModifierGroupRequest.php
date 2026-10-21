<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Modifier_Group create and update validation (Requirements 11.4, 34.3).
 *
 * The minimum selection count must be in [0, 50] and the maximum in [1, 50]. The
 * ordering rule min <= max is a business rule the service rejects with
 * `invalid_modifier_group` (Requirement 11.4), so it is not expressed here.
 */
final class ModifierGroupRequest extends FormRequest
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
            'min_selections' => ['required', 'integer', 'between:0,50'],
            'max_selections' => ['required', 'integer', 'between:1,50'],
            'display_order' => ['required', 'integer', 'between:0,9999'],
        ];
    }
}
