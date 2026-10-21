<?php

declare(strict_types=1);

namespace App\Modules\Account\Domain;

/**
 * The Customer-facing projection of a profile (Requirement 31.1).
 *
 * By construction it carries no password hash and no email-change confirmation
 * token: there is nowhere on this value to put one. The Notification
 * preferences are a list of {category, channel, enabled} flags for the `email`
 * and `in_app` channels.
 */
final readonly class ProfileView
{
    /**
     * @param  list<array{category: string, channel: string, enabled: bool}>  $notificationPreferences
     */
    public function __construct(
        public string $userId,
        public string $displayName,
        public string $email,
        public ?string $phone,
        public array $notificationPreferences,
        public ?string $defaultAddressId,
        public string $createdAt,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     display_name: string,
     *     email: string,
     *     phone: ?string,
     *     notification_preferences: list<array{category: string, channel: string, enabled: bool}>,
     *     default_address_id: ?string,
     *     created_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->userId,
            'display_name' => $this->displayName,
            'email' => $this->email,
            'phone' => $this->phone,
            'notification_preferences' => $this->notificationPreferences,
            'default_address_id' => $this->defaultAddressId,
            'created_at' => $this->createdAt,
        ];
    }
}
