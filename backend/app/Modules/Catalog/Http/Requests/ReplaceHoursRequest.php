<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Domain\SubmittedSchedule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for a submitted weekly schedule (Requirements 11.7, 11.8,
 * 32.5, 32.6).
 *
 * The Form_Request guards only the request *shape*: the body carries an `hours`
 * object whose keys are day names and whose values are arrays of interval
 * objects, each a `start_minute` / `end_minute` pair of integers. A shape
 * violation — a non-integer minute, a missing key, a non-array `hours` — is a
 * generic `validation_failed` at HTTP 422, the same as every other malformed
 * request.
 *
 * The interval *rules* of Requirement 32 criterion 5 (minute bounds, start
 * before end, at most three per day, no overlap, and the seven permitted day
 * names) are deliberately NOT enforced here. They carry the distinct
 * `invalid_hours` code with one per-field detail per violating interval
 * (criterion 6), which the {@see SubmittedSchedule}
 * parser produces inside the service. Enforcing them here would collapse them
 * into `validation_failed` and lose that distinction, so the request accepts any
 * integer minutes and any day-name string and lets the domain reject the rule
 * breaches.
 *
 * An omitted or empty `hours` object is a valid submission: it clears the
 * owner's schedule, leaving every day closed (Requirement 32.5).
 */
final class ReplaceHoursRequest extends FormRequest
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
            'hours' => ['sometimes', 'array'],
            // Each day maps to a list of interval objects.
            'hours.*' => ['array'],
            // Each interval carries two integer minute bounds. The numeric range
            // and ordering are interval rules, enforced by the domain as
            // invalid_hours, so only the integer shape is asserted here.
            'hours.*.*' => ['array'],
            'hours.*.*.start_minute' => ['required', 'integer'],
            'hours.*.*.end_minute' => ['required', 'integer'],
        ];
    }

    /**
     * The submitted schedule as the day-keyed structure the
     * {@see SubmittedSchedule} parser validates.
     *
     * @return array<string, list<array{start_minute: int, end_minute: int}>>
     */
    public function submittedSchedule(): array
    {
        /** @var array<string, mixed> $hours */
        $hours = (array) $this->validated('hours', []);

        $schedule = [];

        foreach ($hours as $day => $intervals) {
            $schedule[(string) $day] = array_values(array_map(
                static fn (array $interval): array => [
                    'start_minute' => (int) $interval['start_minute'],
                    'end_minute' => (int) $interval['end_minute'],
                ],
                (array) $intervals,
            ));
        }

        return $schedule;
    }
}
