<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Restaurant profile update validation (Requirements 32.1, 32.12).
 *
 * A name shorter than 1 or longer than 150 characters, a description longer than
 * 2,000, more than 10 cuisine tags, or a cuisine tag shorter than 1 or longer
 * than 50 characters is rejected with HTTP 422 and `validation_failed` carrying
 * one detail per violating field. The IANA time-zone validity the string rule
 * cannot express, and the currency code's membership of ISO 4217, are enforced
 * by the service against the stored enumerations.
 */
final class RestaurantProfileRequest extends FormRequest
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
            'name' => ['required', 'string', 'min:1', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'currency' => ['required', 'string', 'size:3'],
            'tax_rate_bp' => ['required', 'integer', 'between:0,3000'],
            'base_delivery_fee' => ['required', 'integer', 'between:0,99999999'],
            'time_zone' => ['required', 'string', 'max:64'],
            'cuisine_tags' => ['present', 'array', 'max:10'],
            'cuisine_tags.*' => ['string', 'min:1', 'max:50'],
        ];
    }
}
