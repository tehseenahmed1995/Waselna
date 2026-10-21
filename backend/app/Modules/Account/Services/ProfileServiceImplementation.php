<?php

declare(strict_types=1);

namespace App\Modules\Account\Services;

use App\Modules\Account\Boundary\AccountProvisioning;
use App\Modules\Account\Contracts\Services\ProfileService;
use App\Modules\Account\Domain\NotificationPreferenceDefaults;
use App\Modules\Account\Domain\ProfileView;
use App\Modules\Account\Domain\RequestEmailChangeCommand;
use App\Modules\Account\Domain\UpdateProfileCommand;
use App\Modules\Account\Models\EmailChangeToken;
use App\Modules\Account\Models\NotificationPreference;
use App\Modules\Account\Models\UserProfile;
use App\Modules\Account\Repositories\ProfileRepository;
use App\Modules\Auth\Boundary\CredentialDirectory;
use App\Modules\Auth\Domain\ChangePasswordCommand;
use App\Modules\Auth\Models\User;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\Clock;
use App\Shared\Support\IdGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Own-profile read and partial update, the email-change token flow, and the
 * password-change delegation (Requirements 31.1–31.6, 31.12–31.14).
 *
 * Time is read only through the injected {@see Clock} so the 24-hour email
 * change window is deterministic under test; identifiers come only from the
 * injected {@see IdGenerator}. Password change is delegated across the Auth
 * {@see CredentialDirectory} boundary because the password hash and
 * Refresh_Tokens belong to the Auth module.
 */
final class ProfileServiceImplementation implements AccountProvisioning, ProfileService
{
    private const EMAIL_CHANGE_TTL_HOURS = 24;

    private const EMAIL_MAX = 254;

    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly CredentialDirectory $credentials,
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
    ) {}

    public function getOwnProfile(string $userId): ProfileView
    {
        return $this->project($userId);
    }

    /**
     * Creates the Account-owned records for a newly registered Customer
     * (Requirements 1.1, 16.12).
     *
     * Writes the single `user_profiles` row and one `notification_preferences`
     * row per Notification_Category and channel pair, each set to the default
     * held in Platform_Configuration ({@see NotificationPreferenceDefaults}).
     * The Auth module calls this from inside the registration transaction it
     * opened, so these writes commit or roll back together with the `users` row;
     * this method opens no transaction of its own. Identifiers come only from
     * the injected {@see IdGenerator}.
     *
     * The write is idempotent at the profile grain: a Customer who already has a
     * profile is left untouched, so a replayed provision creates no duplicate
     * row (the `UNIQUE(user_id)` and `UNIQUE(user_id, category, channel)`
     * constraints back the same guarantee).
     */
    public function provisionCustomerProfile(string $userId): void
    {
        if ($this->profiles->profileExists($userId)) {
            return;
        }

        $this->profiles->createProfile($userId);

        foreach (NotificationPreferenceDefaults::matrix() as $default) {
            $this->profiles->createPreference(
                $this->ids->ulid(),
                $userId,
                $default['category'],
                $default['channel'],
                $default['enabled'],
            );
        }
    }

    public function updateProfile(UpdateProfileCommand $command): ProfileView
    {
        return DB::transaction(function () use ($command): ProfileView {
            $profile = $this->profiles->findProfile($command->userId);

            if ($profile === null) {
                $profile = new UserProfile(['user_id' => $command->userId]);
            }

            // Leave every unsubmitted field unchanged (Requirement 31.2).
            if ($command->displayNameWasSubmitted) {
                // The display name lives on the Auth-owned users row; it is the
                // account's canonical display name, so write it there.
                User::query()
                    ->whereKey($command->userId)
                    ->update(['display_name' => $command->displayName]);
            }

            if ($command->phoneWasSubmitted) {
                // A submitted empty phone removes the stored number
                // (Requirement 31.2); a value replaces it.
                $profile->phone_encrypted = $command->phone;
                $profile->save();
            } elseif (! $profile->exists) {
                // Ensure a row exists so later reads are stable, without
                // changing any field the caller did not submit.
                $profile->save();
            }

            foreach ($command->notificationPreferences as $change) {
                $existing = $this->profiles->findPreference(
                    $command->userId,
                    $change->category,
                    $change->channel,
                );

                if ($existing === null) {
                    $existing = new NotificationPreference([
                        'id' => $this->ids->ulid(),
                        'user_id' => $command->userId,
                        'category' => $change->category,
                        'channel' => $change->channel,
                    ]);
                }

                $existing->enabled = $change->enabled;
                $existing->save();
            }

            return $this->project($command->userId);
        });
    }

    public function requestEmailChange(RequestEmailChangeCommand $command): string
    {
        $newEmail = trim($command->newEmail);
        $newEmailLower = mb_strtolower($newEmail);

        if (mb_strlen($newEmail) > self::EMAIL_MAX) {
            throw ValidationException::forField(
                'email',
                'max:254',
                'The email address must be at most 254 characters.',
            );
        }

        // An address already held by another Platform user is rejected and no
        // token is issued (Requirement 31.14).
        if ($this->profiles->emailTakenByAnother($newEmailLower, $command->userId)) {
            throw ValidationException::because(
                'email_already_registered',
                'That email address is already registered.',
            );
        }

        $plain = Str::random(64);
        $now = $this->clock->nowUtc();

        return DB::transaction(function () use ($command, $newEmail, $newEmailLower, $plain, $now): string {
            // Issuing a token invalidates every previously issued one
            // (Requirement 31.4).
            $this->profiles->invalidateLiveTokens($command->userId, $now);

            $token = new EmailChangeToken([
                'id' => $this->ids->ulid(),
                'user_id' => $command->userId,
                'new_email' => $newEmail,
                'new_email_lower' => $newEmailLower,
                'token_hash' => $this->hash($plain),
                'expires_at' => $now->modify('+'.self::EMAIL_CHANGE_TTL_HOURS.' hours'),
                'created_at' => $now,
            ]);
            $token->save();

            return $plain;
        });
    }

    public function confirmEmailChange(string $userId, string $token): ProfileView
    {
        $now = $this->clock->nowUtc();
        $record = $this->profiles->findLiveTokenByHash($userId, $this->hash($token));

        // Unknown, already used, or expired tokens are rejected identically and
        // the stored email address is left unchanged (Requirement 31.13).
        if ($record === null || $record->expires_at->lt($now)) {
            throw ValidationException::because(
                'invalid_confirmation_token',
                'The email change confirmation token is not valid.',
            );
        }

        // A race could let the new address be taken between request and
        // confirmation; re-check so two Customers cannot land on one address.
        if ($this->profiles->emailTakenByAnother($record->new_email_lower, $userId)) {
            throw ValidationException::because(
                'email_already_registered',
                'That email address is already registered.',
            );
        }

        DB::transaction(function () use ($userId, $record, $now): void {
            // Replace exactly the email address; password hash, display name,
            // phone, and preferences are untouched (Requirement 31.12).
            User::query()->whereKey($userId)->update([
                'email' => $record->new_email,
                'email_lower' => $record->new_email_lower,
            ]);

            // Invalidate the token so it can never be replayed (Requirement 31.12).
            $this->profiles->markTokenUsed($record->id, $now);
        });

        return $this->project($userId);
    }

    public function changePassword(ChangePasswordCommand $command): void
    {
        // The password hash and Refresh_Tokens belong to Auth; cross the
        // boundary rather than touching those tables here.
        $this->credentials->changePassword($command);
    }

    /**
     * Builds the Customer-facing projection (Requirement 31.1).
     */
    private function project(string $userId): ProfileView
    {
        /** @var User $user */
        $user = User::query()->findOrFail($userId);
        $profile = $this->profiles->findProfile($userId);

        $preferences = array_map(
            static fn (NotificationPreference $p): array => [
                'category' => $p->category,
                'channel' => $p->channel,
                'enabled' => $p->enabled,
            ],
            $this->profiles->notificationPreferences($userId),
        );

        $createdAt = $user->created_at instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($user->created_at)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(DATE_ATOM)
            : (string) $user->created_at;

        return new ProfileView(
            userId: $user->id,
            displayName: (string) $user->display_name,
            email: (string) $user->email,
            phone: $profile?->phone_encrypted,
            notificationPreferences: $preferences,
            defaultAddressId: $this->profiles->defaultAddressId($userId),
            createdAt: $createdAt,
        );
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
