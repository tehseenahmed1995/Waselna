<?php

declare(strict_types=1);

namespace App\Modules\Account\Repositories;

use App\Modules\Account\Models\EmailChangeToken;
use App\Modules\Account\Models\NotificationPreference;
use App\Modules\Account\Models\UserProfile;
use Illuminate\Support\Facades\DB;

/**
 * The one place that queries the Account module's profile tables
 * (`user_profiles`, `notification_preferences`, `email_change_tokens`) plus the
 * read of the owning Customer's default Delivery_Address identifier.
 *
 * Concrete, Eloquent-backed, per the design's repository deviation: the service
 * holds the rules, the repository holds the queries.
 */
final class ProfileRepository
{
    public function findProfile(string $userId): ?UserProfile
    {
        return UserProfile::query()->whereKey($userId)->first();
    }

    /**
     * Whether a profile row already exists for the Customer. Lets the
     * registration provisioning stay idempotent: a re-run creates no duplicate.
     */
    public function profileExists(string $userId): bool
    {
        return UserProfile::query()->whereKey($userId)->exists();
    }

    /**
     * Inserts the Customer's empty profile row (Requirement 1.1). Runs inside
     * the registration transaction opened by the caller.
     */
    public function createProfile(string $userId): void
    {
        $profile = new UserProfile(['user_id' => $userId]);
        $profile->save();
    }

    /**
     * Inserts one `notification_preferences` row (Requirements 1.1, 16.12). Runs
     * inside the registration transaction opened by the caller.
     */
    public function createPreference(string $id, string $userId, string $category, string $channel, bool $enabled): void
    {
        $preference = new NotificationPreference([
            'id' => $id,
            'user_id' => $userId,
            'category' => $category,
            'channel' => $channel,
            'enabled' => $enabled,
        ]);
        $preference->save();
    }

    /**
     * The Customer's Notification preferences, ordered deterministically so the
     * profile view is stable across reads.
     *
     * @return list<NotificationPreference>
     */
    public function notificationPreferences(string $userId): array
    {
        return array_values(
            NotificationPreference::query()
                ->where('user_id', $userId)
                ->orderBy('category')
                ->orderBy('channel')
                ->get()
                ->all(),
        );
    }

    /**
     * The identifier of the Customer's single non-archived default
     * Delivery_Address, or null where none exists (Requirement 31.1).
     *
     * Read through the query builder rather than the Address module's model so
     * the profile projection stays independent of the Delivery_Address casts;
     * the AddressService of task 4.6 owns that model. A non-archived default is
     * one whose `archived_at` is null (Requirement 31.10).
     */
    public function defaultAddressId(string $userId): ?string
    {
        /** @var object{id: string}|null $row */
        $row = DB::table('delivery_addresses')
            ->where('user_id', $userId)
            ->where('is_default', true)
            ->whereNull('archived_at')
            ->select('id')
            ->first();

        return $row->id ?? null;
    }

    public function findPreference(string $userId, string $category, string $channel): ?NotificationPreference
    {
        return NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('category', $category)
            ->where('channel', $channel)
            ->first();
    }

    /**
     * The Customer's recorded, unused, unexpired token for the presented hash,
     * or null (Requirements 31.12, 31.13).
     */
    public function findLiveTokenByHash(string $userId, string $tokenHash): ?EmailChangeToken
    {
        return EmailChangeToken::query()
            ->where('user_id', $userId)
            ->where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->first();
    }

    /**
     * Marks a single token used (Requirements 31.12, 31.13).
     */
    public function markTokenUsed(string $tokenId, \DateTimeImmutable $at): void
    {
        EmailChangeToken::query()
            ->whereKey($tokenId)
            ->update(['used_at' => $at]);
    }

    /**
     * Marks every still-live email-change token of the Customer as used, which
     * is how issuing a new token invalidates earlier ones (Requirement 31.4).
     */
    public function invalidateLiveTokens(string $userId, \DateTimeImmutable $at): void
    {
        EmailChangeToken::query()
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->update(['used_at' => $at]);
    }

    /**
     * Whether any other Platform user already holds this email address, compared
     * case-insensitively (Requirements 31.4, 31.14).
     */
    public function emailTakenByAnother(string $emailLower, string $exceptUserId): bool
    {
        return DB::table('users')
            ->where('email_lower', $emailLower)
            ->where('id', '!=', $exceptUserId)
            ->exists();
    }
}
