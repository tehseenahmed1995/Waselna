<?php

declare(strict_types=1);

namespace App\Modules\Account\Boundary;

use App\Modules\Account\Domain\Channel;
use App\Modules\Account\Domain\NotificationCategory;

/**
 * The Module_Boundary the Account module exposes for the Notification module's
 * preference read (design Account notes; Requirements 16.4, 16.11, 22.8).
 *
 * The Notification module owns no preference data: a Customer's per-category,
 * per-channel enabled flags live in the Account module's
 * `notification_preferences` table. Before sending a Notification on a channel,
 * the Notification module asks this boundary whether the recipient leaves that
 * category enabled on that channel, and omits the Notification where it does not
 * (Requirement 16.4). Crossing the boundary rather than reading the Account
 * table keeps the ownership rule of Requirement 22 intact.
 *
 * A category or channel is named by its enum type, so a consumer cannot ask
 * about a value outside the five categories and two channels the Platform
 * defines (Requirements 16.6, 16.11).
 */
interface NotificationPreferenceReader
{
    /**
     * Whether $userId leaves $category enabled on $channel.
     *
     * Returns true where no explicit preference row exists, so a Notification is
     * omitted only on an explicit opt-out; absence of a row is treated as
     * enabled, matching the registration default that enables every category on
     * both channels unless Platform_Configuration says otherwise
     * (Requirement 16.12).
     */
    public function isEnabled(string $userId, NotificationCategory $category, Channel $channel): bool;
}
