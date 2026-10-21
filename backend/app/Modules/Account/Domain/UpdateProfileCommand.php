<?php

declare(strict_types=1);

namespace App\Modules\Account\Domain;

/**
 * A partial profile update (Requirement 31.2).
 *
 * The command distinguishes three states per field so the service can change
 * exactly what was submitted (Property 71):
 *
 *  - absent: the key was not in the request; the stored value is left unchanged.
 *  - present with a value: the stored value is replaced.
 *  - present with an empty value (phone only): the stored value is removed.
 *
 * Presence is carried by the explicit `*WasSubmitted` flags rather than by null,
 * because null is itself a meaningful submitted value for the phone number.
 */
final readonly class UpdateProfileCommand
{
    /**
     * @param  list<NotificationPreferenceChange>  $notificationPreferences
     */
    public function __construct(
        public string $userId,
        public bool $displayNameWasSubmitted,
        public ?string $displayName,
        public bool $phoneWasSubmitted,
        public ?string $phone,
        public array $notificationPreferences,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the validated request payload
     */
    public static function fromValidated(string $userId, array $input): self
    {
        $displayNameWasSubmitted = array_key_exists('display_name', $input);
        $phoneWasSubmitted = array_key_exists('phone', $input);

        $preferences = [];

        if (array_key_exists('notification_preferences', $input) && is_array($input['notification_preferences'])) {
            foreach ($input['notification_preferences'] as $preference) {
                if (! is_array($preference)) {
                    continue;
                }

                $preferences[] = new NotificationPreferenceChange(
                    (string) ($preference['category'] ?? ''),
                    (string) ($preference['channel'] ?? ''),
                    (bool) ($preference['enabled'] ?? false),
                );
            }
        }

        $phone = null;

        if ($phoneWasSubmitted && $input['phone'] !== null) {
            $phone = trim((string) $input['phone']);
        }

        return new self(
            $userId,
            $displayNameWasSubmitted,
            $displayNameWasSubmitted ? (string) $input['display_name'] : null,
            $phoneWasSubmitted,
            $phone === '' ? null : $phone,
            $preferences,
        );
    }
}
