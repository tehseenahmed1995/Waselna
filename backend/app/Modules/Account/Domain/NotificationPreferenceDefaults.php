<?php

declare(strict_types=1);

namespace App\Modules\Account\Domain;

/**
 * The default Notification-preference matrix a newly created Platform user holds
 * (Requirement 16.12): one enabled-or-disabled flag for every
 * {@see NotificationCategory} on every {@see Channel}.
 *
 * Requirement 16.12 sources these defaults from Platform_Configuration's
 * `notification_defaults`, which the Admin module owns and exposes through its
 * `PlatformConfigurationReader` boundary (design "Admin_Module"). Until that
 * reader is wired, this value object is the single place that produces the
 * matrix, so swapping the source later changes one call site. The Platform
 * default leaves every category enabled on both channels, matching the
 * `notification_preferences.enabled` column default; a Customer opts out
 * afterwards through the profile-update flow of Requirement 31.2.
 *
 * The matrix is exactly the five categories times the two channels, giving the
 * ten rows the registration transaction inserts (design "Transaction
 * boundaries").
 */
final class NotificationPreferenceDefaults
{
    /**
     * The default preference matrix in canonical (category, channel) order.
     *
     * @return list<array{category: string, channel: string, enabled: bool}>
     */
    public static function matrix(): array
    {
        $rows = [];

        foreach (NotificationCategory::cases() as $category) {
            foreach (Channel::cases() as $channel) {
                $rows[] = [
                    'category' => $category->value,
                    'channel' => $channel->value,
                    'enabled' => self::defaultEnabled($category, $channel),
                ];
            }
        }

        return $rows;
    }

    /**
     * The default enabled flag for a single category and channel pair. Every
     * pair defaults to enabled; a Customer disables a channel afterwards through
     * the profile-update flow (Requirement 31.2).
     */
    private static function defaultEnabled(NotificationCategory $category, Channel $channel): bool
    {
        return true;
    }
}
