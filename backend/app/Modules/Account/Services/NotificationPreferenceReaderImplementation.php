<?php

declare(strict_types=1);

namespace App\Modules\Account\Services;

use App\Modules\Account\Boundary\NotificationPreferenceReader;
use App\Modules\Account\Domain\Channel;
use App\Modules\Account\Domain\NotificationCategory;
use App\Modules\Account\Repositories\ProfileRepository;

/**
 * The Account module's implementation of the {@see NotificationPreferenceReader}
 * boundary (Requirements 16.4, 16.11).
 *
 * It answers one question for the Notification module: does this Customer leave
 * this category enabled on this channel? The flag lives in the Account module's
 * `notification_preferences` table, read here through the same
 * {@see ProfileRepository} the profile flow uses, so the Notification module
 * never touches an Account table.
 *
 * A missing preference row is treated as enabled: registration seeds a row per
 * category and channel from Platform_Configuration (Requirement 16.12), so in
 * practice a row always exists, but defaulting absence to enabled means a
 * Notification is suppressed only on an explicit opt-out, never on a gap in the
 * data.
 */
final class NotificationPreferenceReaderImplementation implements NotificationPreferenceReader
{
    public function __construct(private readonly ProfileRepository $profiles) {}

    public function isEnabled(string $userId, NotificationCategory $category, Channel $channel): bool
    {
        $preference = $this->profiles->findPreference($userId, $category->value, $channel->value);

        if ($preference === null) {
            return true;
        }

        return $preference->enabled;
    }
}
