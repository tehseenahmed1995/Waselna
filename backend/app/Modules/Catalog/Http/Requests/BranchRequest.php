<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Restaurant_Branch create and update validation (Requirements 32.2, 32.12).
 *
 * A name shorter than 1 or longer than 150 characters is rejected with HTTP 422
 * and `validation_failed`; the first address line is required so there is
 * something to resolve. The optional time zone's IANA validity the string rule
 * cannot express is enforced by the service, which also turns the submitted
 * address into the resolved coordinate pair through the Zone module.
 */
final class BranchRequest extends FormRequest
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
            'address_line_1' => ['required', 'string', 'min:1', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'time_zone' => ['nullable', 'string', 'max:64'],
        ];
    }
}
