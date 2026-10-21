<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain;

/**
 * The Platform_Configuration values that apply when no configuration row has
 * been written yet ("single-row configuration semantics with sensible
 * defaults").
 *
 * These constants are the single source of the Platform defaults, shared by the
 * seed migration that inserts the first row and by the reader's fallback when
 * the table is empty, so a fresh database and a seeded one agree. Each value
 * matches the column default declared on the `platform_configuration` table in
 * `2024_01_07_000001_create_remaining_tables`, and each stays inside the range
 * Requirement 37.10 permits:
 *
 *  - Service_Fee percentage in the closed range 0..2500 basis points (0..25%).
 *  - Offer_Radii in the closed range 1..20 kilometres with initial <= maximum.
 *  - Service_Fee absolute bounds with minimum <= maximum.
 *
 * The Notification defaults leave every Notification_Category enabled on both
 * channels, matching the Account module's registration default
 * (Requirement 16.12); the Account module consumes this map through the reader
 * boundary so the two modules never diverge.
 */
final class PlatformConfigurationDefaults
{
    public const SERVICE_FEE_PERCENTAGE_MIN_BP = 500;

    public const SERVICE_FEE_PERCENTAGE_MAX_BP = 1500;

    public const DELIVERY_FEE_MIN_MINOR = 0;

    public const DELIVERY_FEE_MAX_MINOR = 5000;

    public const OFFER_RADIUS_KM_DEFAULT = 5;

    public const OFFER_RADIUS_KM_MAX = 20;

    /**
     * The default currency the Service_Fee bounds are expressed in. Platform
     * configuration is single-currency in this build; the Pricing_Engine
     * asserts the Cart currency matches when it applies the bounds.
     */
    public const CURRENCY = 'USD';

    /**
     * The five Notification_Category identifiers (Requirement 16.11), used to
     * build the default enabled matrix.
     *
     * @var list<string>
     */
    public const NOTIFICATION_CATEGORIES = [
        'order_status',
        'delivery_status',
        'payment',
        'promotion',
        'account',
    ];

    /**
     * The two channels the Platform supports (Requirement 16.6).
     *
     * @var list<string>
     */
    public const NOTIFICATION_CHANNELS = [
        'email',
        'in_app',
    ];

    /**
     * The default Order rejection reasons a Restaurant may cite
     * (Requirement 12.3). Administrators may replace this list.
     *
     * @var list<string>
     */
    public const REJECTION_REASONS = [
        'out_of_stock',
        'kitchen_closed',
        'too_busy',
        'cannot_fulfil_special_request',
        'other',
    ];

    /**
     * The default Notification-preference matrix: every category enabled on
     * every channel (Requirement 16.12), keyed `category -> channel -> bool`.
     *
     * @return array<string, array<string, bool>>
     */
    public static function notificationDefaults(): array
    {
        $matrix = [];

        foreach (self::NOTIFICATION_CATEGORIES as $category) {
            foreach (self::NOTIFICATION_CHANNELS as $channel) {
                $matrix[$category][$channel] = true;
            }
        }

        return $matrix;
    }
}
