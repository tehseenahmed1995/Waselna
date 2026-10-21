<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Domain\UpdateConfigurationCommand;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Platform_Configuration update validation (Requirements 37.9, 37.10).
 *
 * This stage rejects a request whose *shape* is wrong — a missing field, a
 * non-integer bound, a non-array reason list — with HTTP 422 and
 * `validation_failed`. The *range* rules of Requirement 37.10 (0..2500 basis
 * points, 1..20 kilometres, min <= max) are enforced by the service against the
 * command so a single authority owns them whatever the caller, so the bounds
 * here only reject plainly impossible values and keep the real decision in the
 * domain.
 */
final class UpdateConfigurationRequest extends FormRequest
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
            'service_fee_basis_points' => ['required', 'integer', 'min:0'],
            'service_fee_min' => ['required', 'integer', 'min:0'],
            'service_fee_max' => ['required', 'integer', 'min:0'],
            'initial_offer_radius_km' => ['required', 'integer', 'min:0'],
            'max_offer_radius_km' => ['required', 'integer', 'min:0'],
            'order_rejection_reasons' => ['required', 'array', 'min:1'],
            'order_rejection_reasons.*' => ['required', 'string', 'min:1', 'max:100'],
            'notification_defaults' => ['required', 'array'],
            'notification_defaults.*' => ['required', 'array'],
            'notification_defaults.*.*' => ['required', 'boolean'],
        ];
    }

    /**
     * Builds the framework-free command the service validates and persists.
     */
    public function toCommand(): UpdateConfigurationCommand
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        /** @var array<int, string> $reasons */
        $reasons = array_values($validated['order_rejection_reasons']);

        /** @var array<string, array<string, bool>> $notificationDefaults */
        $notificationDefaults = [];
        foreach ($validated['notification_defaults'] as $category => $channels) {
            foreach ($channels as $channel => $enabled) {
                $notificationDefaults[(string) $category][(string) $channel] = (bool) $enabled;
            }
        }

        return new UpdateConfigurationCommand(
            serviceFeeBasisPoints: (int) $validated['service_fee_basis_points'],
            serviceFeeMinimumMinor: (int) $validated['service_fee_min'],
            serviceFeeMaximumMinor: (int) $validated['service_fee_max'],
            initialOfferRadiusKm: (int) $validated['initial_offer_radius_km'],
            maxOfferRadiusKm: (int) $validated['max_offer_radius_km'],
            orderRejectionReasons: $reasons,
            notificationDefaults: $notificationDefaults,
        );
    }
}
