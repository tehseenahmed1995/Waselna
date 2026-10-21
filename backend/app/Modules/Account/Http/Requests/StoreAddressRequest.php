<?php

declare(strict_types=1);

namespace App\Modules\Account\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Delivery_Address create and update validation (Requirements 31.3, 31.7).
 *
 * Every field is required except the optional second address line; a value
 * outside its documented length, or a `country_code` that is not two letters,
 * is rejected with HTTP 422 and `validation_failed` carrying one detail per
 * violating field (Requirement 31.3). Whether the resolved coordinate is
 * serviceable is not decided here — the Zone module resolves the coordinate and
 * the service stores whatever resolves (Requirement 31.7).
 */
final class StoreAddressRequest extends FormRequest
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
            'label' => ['required', 'string', 'min:1', 'max:50'],
            'line1' => ['required', 'string', 'min:1', 'max:200'],
            'line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'locality' => ['required', 'string', 'min:1', 'max:100'],
            'postal_code' => ['required', 'string', 'min:1', 'max:20'],
            // ISO 3166-1 alpha-2: exactly two ASCII letters. Normalised to upper
            // case in the command; the regex accepts either case here.
            'country_code' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
        ];
    }
}
