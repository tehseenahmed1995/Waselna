<?php

declare(strict_types=1);

namespace App\Modules\Account\Domain;

use App\Modules\Account\Boundary\NotificationPreferenceReader;

/**
 * The two Notification channels the Platform supports (Requirements 16.6, 31.1,
 * 31.2).
 *
 * Held as an enum for the same reason as {@see NotificationCategory}: the
 * {@see NotificationPreferenceReader} boundary
 * names a channel by type, so a consumer cannot ask whether a category is
 * enabled on a channel outside this set.
 */
enum Channel: string
{
    case Email = 'email';

    case InApp = 'in_app';

    /**
     * The channel values in canonical order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
