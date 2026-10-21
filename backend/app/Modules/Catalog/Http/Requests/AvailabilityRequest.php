<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Availability-toggle validation for a Menu_Item or Product_Variant
 * (Requirements 34.7, 34.8).
 *
 * The one submitted field is the new availability flag. A missing or
 * non-boolean `is_available` is rejected with HTTP 422 and `validation_failed`
 * by the request pipeline before the Service runs, so the Service always
 * receives a well-formed boolean. The Role gate on the route restricts the
 * actors to a Restaurant_Operator, an Administrator, or a Restaurant_Staff; the
 * ownership check lives in the Controller.
 */
final class AvailabilityRequest extends FormRequest
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
            'is_available' => ['required', 'boolean'],
        ];
    }
}
