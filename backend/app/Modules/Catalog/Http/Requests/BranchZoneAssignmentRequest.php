<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Delivery_Zone assignment validation (Requirements 32.11, 32.12).
 *
 * The body carries the complete set of Delivery_Zone identifiers to assign to a
 * Restaurant_Branch. The at-most-20 count and the rejection of a zone the
 * Restaurant does not own are decided by the service against the Zone module's
 * ownership set; this request only pins the shape — a present array of strings.
 */
final class BranchZoneAssignmentRequest extends FormRequest
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
            'zone_ids' => ['present', 'array'],
            'zone_ids.*' => ['string'],
        ];
    }
}
