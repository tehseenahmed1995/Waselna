<?php

declare(strict_types=1);

namespace App\Modules\Account\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial profile update validation (Requirements 31.2, 31.3).
 *
 * Every field is optional so the update stays partial; `present`/`sometimes`
 * rules keep an absent field absent while still validating a submitted one. A
 * submitted empty phone is permitted (it removes the stored number).
 */
final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A submitted empty phone means "remove the stored number"
     * (Requirement 31.2). Normalise it to null here so it survives validation
     * as an explicit removal rather than failing the 5-character minimum, while
     * the key stays present so the command still treats it as submitted.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('phone') && is_string($this->input('phone')) && trim((string) $this->input('phone')) === '') {
            $this->merge(['phone' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'string', 'min:1', 'max:100'],

            // Nullable so an empty value is accepted as "remove"; the regex is
            // digits, spaces, hyphens, and a single leading plus sign.
            'phone' => ['sometimes', 'nullable', 'string', 'min:5', 'max:20', 'regex:/^\+?[0-9 \-]+$/'],

            'notification_preferences' => ['sometimes', 'array'],
            'notification_preferences.*.category' => ['required_with:notification_preferences', 'string', 'max:64'],
            'notification_preferences.*.channel' => ['required_with:notification_preferences', 'string', Rule::in(['email', 'in_app'])],
            'notification_preferences.*.enabled' => ['required_with:notification_preferences', 'boolean'],
        ];
    }
}
