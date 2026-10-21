<?php

declare(strict_types=1);

namespace App\Modules\Account\Domain;

use App\Modules\Account\Boundary\NotificationPreferenceReader;

/**
 * The five Notification categories every Notification carries (Requirement 16.11,
 * glossary "Notification_Category").
 *
 * Held as an enum so the {@see NotificationPreferenceReader}
 * boundary names a category by type rather than a bare string: a consumer in the
 * Notification module cannot pass a category outside this set, and the own-profile
 * view (Requirement 31.1) reports exactly these five against each channel.
 */
enum NotificationCategory: string
{
    case OrderStatus = 'order_status';

    case DeliveryStatus = 'delivery_status';

    case Payment = 'payment';

    case Promotion = 'promotion';

    case Account = 'account';

    /**
     * The category values in their canonical order, used to build the default
     * preference matrix and to validate a submitted category.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
