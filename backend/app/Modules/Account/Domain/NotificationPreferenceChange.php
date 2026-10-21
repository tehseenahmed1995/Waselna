<?php

declare(strict_types=1);

namespace App\Modules\Account\Domain;

/**
 * One submitted Notification preference change: enable or disable a single
 * category on a single channel (Requirement 31.2).
 */
final readonly class NotificationPreferenceChange
{
    public function __construct(
        public string $category,
        public string $channel,
        public bool $enabled,
    ) {}
}
