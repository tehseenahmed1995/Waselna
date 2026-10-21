<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Account\Boundary\AccountProvisioning;
use App\Modules\Auth\Boundary\CredentialDirectory;
use App\Modules\Auth\Domain\ActorContext;
use App\Modules\Auth\Domain\ChangePasswordCommand;
use App\Modules\Auth\Models\AccessToken;
use App\Modules\Auth\Models\RefreshToken;
use App\Modules\Auth\Models\User;
use App\Shared\Domain\Exceptions\AuthorizationException;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\Clock;
use App\Shared\Support\IdGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Credentials, opaque database-backed tokens, and Role resolution (Requirement 1).
 */
final class AuthenticationService implements CredentialDirectory
{
    private const PASSWORD_MIN = 12;

    private const PASSWORD_MAX = 128;

    private const ACCESS_TTL_MINUTES = 60;

    private const REFRESH_TTL_DAYS = 30;

    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 900;

    /**
     * @param  \Closure(): AccountProvisioning  $accountProvisioning  resolved lazily
     *                                                                inside {@see self::register()} only. Injecting the Account
     *                                                                provisioning boundary eagerly would form a container cycle -
     *                                                                AuthenticationService is bound to CredentialDirectory, which
     *                                                                ProfileServiceImplementation (the AccountProvisioning binding)
     *                                                                depends on - so the edge is deferred behind a Closure and the two
     *                                                                services are only ever wired when registration actually runs.
     */
    public function __construct(
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
        private readonly \Closure $accountProvisioning,
    ) {}

    /**
     * @return array{id: string, email: string, display_name: string, role: string}
     */
    public function register(string $email, string $password, string $displayName): array
    {
        $emailLower = mb_strtolower(trim($email));

        if (User::query()->where('email_lower', $emailLower)->exists()) {
            throw ValidationException::because('email_already_registered', 'That email address is already registered.');
        }

        $passwordHash = Hash::make($password);

        // Registration creates the Auth-owned `users` row and the Account-owned
        // `user_profiles` row plus the ten `notification_preferences` rows in one
        // transaction (design "Transaction boundaries"; Requirements 1.1, 16.12).
        // A failure in either half rolls the whole thing back, so no partial
        // Platform user or profile is ever left behind. The Account writes go
        // through the AccountProvisioning Module_Boundary rather than Account's
        // SQL, keeping the ownership rule of Requirement 22 intact.
        $user = DB::transaction(function () use ($email, $emailLower, $passwordHash, $displayName): User {
            $user = new User([
                'id' => $this->ids->ulid(),
                'email' => $email,
                'email_lower' => $emailLower,
                'password_hash' => $passwordHash,
                'display_name' => $displayName,
                'role' => 'customer',
                'account_status' => 'active',
            ]);
            $user->save();

            ($this->accountProvisioning)()->provisionCustomerProfile($user->id);

            return $user;
        });

        return [
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'role' => $user->role,
        ];
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: string, user: array<string, mixed>}
     */
    public function login(string $email, string $password): array
    {
        $emailLower = mb_strtolower(trim($email));
        $key = 'signin_fail:'.$emailLower;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::because('too_many_attempts', 'Too many failed sign-in attempts. Try again later.');
        }

        $user = User::query()->where('email_lower', $emailLower)->first();

        if ($user === null || ! Hash::check($password, $user->password_hash)) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            throw new AuthorizationException('invalid_credentials', 'The email address or password is incorrect.');
        }

        if ($user->account_status === 'suspended') {
            throw AuthorizationException::accountSuspended();
        }

        RateLimiter::clear($key);

        return $this->issueTokens($user);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: string, user: array<string, mixed>}
     */
    public function refresh(string $refreshToken): array
    {
        $hash = $this->hash($refreshToken);
        $now = $this->clock->nowUtc();

        $record = RefreshToken::query()->where('token_hash', $hash)->first();

        if ($record === null || $record->revoked_at !== null || $record->expires_at->lt($now)) {
            throw new AuthorizationException('invalid_refresh_token', 'The refresh token is not valid.');
        }

        $user = User::query()->findOrFail($record->user_id);

        return DB::transaction(function () use ($record, $user): array {
            $record->revoked_at = $this->clock->nowUtc();
            $record->save();

            AccessToken::query()
                ->where('refresh_token_id', $record->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $this->clock->nowUtc()]);

            return $this->issueTokens($user);
        });
    }

    public function logout(string $accessToken): void
    {
        $hash = $this->hash($accessToken);
        $record = AccessToken::query()->where('token_hash', $hash)->first();

        if ($record === null) {
            return;
        }

        DB::transaction(function () use ($record): void {
            $now = $this->clock->nowUtc();
            $record->revoked_at = $now;
            $record->save();

            if ($record->refresh_token_id !== null) {
                RefreshToken::query()
                    ->where('id', $record->refresh_token_id)
                    ->update(['revoked_at' => $now]);
            }
        });
    }

    /**
     * Changes a Customer's password (Requirements 31.5, 31.6).
     *
     * An out-of-range new password is rejected with `validation_failed` and a
     * current password that does not match the stored hash with
     * `current_password_mismatch`; in both cases the stored hash is left
     * unchanged and no Refresh_Token is revoked. On success the new hash is
     * written and every Refresh_Token of the Customer except the one presented
     * with the request is revoked, so other sessions are signed out while the
     * caller's session continues.
     */
    public function changePassword(ChangePasswordCommand $command): void
    {
        $length = mb_strlen($command->newPassword);

        if ($length < self::PASSWORD_MIN || $length > self::PASSWORD_MAX) {
            throw ValidationException::forField(
                'new_password',
                sprintf('between:%d,%d', self::PASSWORD_MIN, self::PASSWORD_MAX),
                'The new password must be between 12 and 128 characters.',
            );
        }

        $user = User::query()->findOrFail($command->userId);

        if (! Hash::check($command->currentPassword, $user->password_hash)) {
            throw ValidationException::because(
                'current_password_mismatch',
                'The current password is incorrect.',
            );
        }

        $presentedHash = $command->presentedRefreshToken !== null
            ? $this->hash($command->presentedRefreshToken)
            : null;

        DB::transaction(function () use ($user, $command, $presentedHash): void {
            $user->password_hash = Hash::make($command->newPassword);
            $user->save();

            $query = RefreshToken::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at');

            if ($presentedHash !== null) {
                $query->where('token_hash', '!=', $presentedHash);
            }

            $query->update(['revoked_at' => $this->clock->nowUtc()]);
        });
    }

    /**
     * Resolves an Access_Token into the acting Platform user, or null when the
     * token is unknown, revoked, or expired.
     */
    public function resolve(string $accessToken): ?ActorContext
    {
        $record = AccessToken::query()->where('token_hash', $this->hash($accessToken))->first();

        if ($record === null || $record->revoked_at !== null || $record->expires_at->lt($this->clock->nowUtc())) {
            return null;
        }

        $user = User::query()->find($record->user_id);

        if ($user === null) {
            return null;
        }

        return new ActorContext(
            $user->id,
            $user->role,
            $user->account_status,
            $user->restaurant_id,
            $user->branch_id,
        );
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_at: string, user: array<string, mixed>}
     */
    private function issueTokens(User $user): array
    {
        $now = $this->clock->nowUtc();
        $accessPlain = Str::random(64);
        $refreshPlain = Str::random(64);

        $refresh = new RefreshToken([
            'id' => $this->ids->ulid(),
            'user_id' => $user->id,
            'token_hash' => $this->hash($refreshPlain),
            'expires_at' => $now->modify('+'.self::REFRESH_TTL_DAYS.' days'),
            'created_at' => $now,
        ]);
        $refresh->save();

        $accessExpires = $now->modify('+'.self::ACCESS_TTL_MINUTES.' minutes');
        $access = new AccessToken([
            'id' => $this->ids->ulid(),
            'user_id' => $user->id,
            'token_hash' => $this->hash($accessPlain),
            'refresh_token_id' => $refresh->id,
            'expires_at' => $accessExpires,
            'created_at' => $now,
        ]);
        $access->save();

        return [
            'access_token' => $accessPlain,
            'refresh_token' => $refreshPlain,
            'expires_at' => $accessExpires->format(DATE_ATOM),
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'display_name' => $user->display_name,
                'role' => $user->role,
            ],
        ];
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
