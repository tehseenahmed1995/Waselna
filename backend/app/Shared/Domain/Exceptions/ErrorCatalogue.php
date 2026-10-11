<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exceptions;

use InvalidArgumentException;

/**
 * The single catalogue mapping every documented API_Error code to its HTTP
 * status.
 *
 * Requirements 20.3 and 20.4 fix the status per failure class and require a
 * stable code; keeping the mapping in one table is what lets the exception
 * handler render any failure without a per-endpoint branch, and lets a test
 * assert that no code is returned under two different statuses.
 *
 * Codes are added here by the task that introduces the behaviour behind them;
 * the list below is the catalogue of the design document as authored.
 */
final class ErrorCatalogue
{
    /**
     * Code to HTTP status, grouped exactly as the design's catalogue table is.
     *
     * @var array<string, int>
     */
    private const CODES = [
        // 400
        'webhook_signature_invalid' => 400,

        // 401
        'unauthenticated' => 401,
        'invalid_token' => 401,
        'invalid_credentials' => 401,
        'invalid_refresh_token' => 401,

        // 403
        'forbidden' => 403,
        'account_suspended' => 403,
        'channel_forbidden' => 403,

        // 404
        'restaurant_not_found' => 404,
        'menu_item_not_found' => 404,
        'cart_line_not_found' => 404,
        'order_not_found' => 404,
        'address_not_found' => 404,
        'delivery_not_found' => 404,

        // 409
        'cart_restaurant_conflict' => 409,
        'invalid_state_transition' => 409,
        'cancellation_window_closed' => 409,
        'job_already_assigned' => 409,
        'courier_has_active_job' => 409,
        'active_delivery_in_progress' => 409,
        'rider_offline' => 409,
        'restaurant_suspended' => 409,
        'restaurant_not_publishable' => 409,
        'invalid_status_transition' => 409,
        'last_active_branch' => 409,
        'staff_limit_reached' => 409,
        'review_already_exists' => 409,
        'courier_profile_incomplete' => 409,

        // 422
        'validation_failed' => 422,
        'email_already_registered' => 422,
        'invalid_geojson' => 422,
        'address_not_resolvable' => 422,
        'address_not_serviceable' => 422,
        'invalid_modifier_selection' => 422,
        'item_unavailable' => 422,
        'variant_selection_required' => 422,
        'invalid_variant_selection' => 422,
        'cart_line_limit_reached' => 422,
        'invalid_cursor' => 422,
        'invalid_price' => 422,
        'invalid_modifier_group' => 422,
        'invalid_hours' => 422,
        'catalog_limit_reached' => 422,
        'branch_limit_reached' => 422,
        'address_limit_reached' => 422,
        'no_branch_available' => 422,
        'restaurant_closed' => 422,
        'promotion_not_applicable' => 422,
        'order_not_reviewable' => 422,
        'invalid_location_report' => 422,
        'invalid_confirmation_token' => 422,
        'current_password_mismatch' => 422,

        // 429
        'too_many_attempts' => 429,
        'location_report_rate_exceeded' => 429,
        'rate_limit_exceeded' => 429,

        // 500
        'internal_error' => 500,
    ];

    /**
     * The code every unhandled exception collapses to (Requirement 27.9).
     */
    public const INTERNAL_ERROR = 'internal_error';

    /**
     * The code every schema violation collapses to (Requirements 20.4, 28.1).
     */
    public const VALIDATION_FAILED = 'validation_failed';

    /**
     * The code a per-minute rate-limit rejection carries (Requirement 28.3).
     */
    public const RATE_LIMIT_EXCEEDED = 'rate_limit_exceeded';

    public static function knows(string $code): bool
    {
        return isset(self::CODES[$code]);
    }

    /**
     * @throws InvalidArgumentException when the code is not catalogued
     */
    public static function statusFor(string $code): int
    {
        if (! isset(self::CODES[$code])) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a documented API_Error code; add it to the catalogue first.',
                $code,
            ));
        }

        return self::CODES[$code];
    }

    /**
     * Asserts that $code is catalogued under one of $permittedStatuses, which is
     * how each DomainException family keeps its codes inside its own status
     * range.
     *
     * @param  list<int>  $permittedStatuses
     *
     * @throws InvalidArgumentException
     */
    public static function assertCodeHasStatus(string $code, array $permittedStatuses): int
    {
        $status = self::statusFor($code);

        if (! in_array($status, $permittedStatuses, true)) {
            throw new InvalidArgumentException(sprintf(
                'API_Error code "%s" is catalogued under HTTP status %d, not one of %s.',
                $code,
                $status,
                implode(', ', array_map(static fn (int $s): string => (string) $s, $permittedStatuses)),
            ));
        }

        return $status;
    }

    /**
     * @return list<string>
     */
    public static function codesForStatus(int $status): array
    {
        return array_keys(array_filter(
            self::CODES,
            static fn (int $catalogued): bool => $catalogued === $status,
        ));
    }

    /**
     * @return array<string, int>
     */
    public static function all(): array
    {
        return self::CODES;
    }
}
