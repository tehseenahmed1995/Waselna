<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Domain\RestaurantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The target-status validation for a Restaurant_Status transition (Requirements
 * 59.3, 59.6).
 *
 * The one submitted field is the target `status`, which must be one of the four
 * Restaurant_Status values. A missing or out-of-set `status` is rejected with
 * HTTP 422 and `validation_failed` by the request pipeline before the Service
 * runs, so the Service always receives a syntactically valid target. Whether the
 * target is reachable from the current status is a lifecycle rule, not a schema
 * rule, so an unreachable but well-formed pair is left to the Service, which
 * rejects it with `409 invalid_status_transition` — the pipeline here does not
 * pre-empt that distinction.
 */
final class RestaurantStatusTransitionRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(RestaurantStatus::ALL)],
        ];
    }

    /**
     * The validated target Restaurant_Status.
     */
    public function targetStatus(): string
    {
        /** @var string $status */
        $status = $this->validated()['status'];

        return $status;
    }
}
