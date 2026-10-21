<?php

declare(strict_types=1);

// Feature: waselna, Property 72: The email-change token flow preserves sign-in continuity

/*
 * *For any* sequence of email-change requests and confirmations, issuing a token
 * invalidates every previously issued email-change token of that Customer; the
 * stored email address and sign-in behaviour keep using the previous address
 * until a valid token is accepted; accepting a recorded unused unexpired token
 * within 24 hours replaces exactly the email address, leaving password hash,
 * display name, phone number, and Notification preferences unchanged; and an
 * unknown, used, or expired token is rejected with `invalid_confirmation_token`
 * leaving the stored email address unchanged.
 *
 * **Validates: Requirements 31.4, 31.12, 31.13**
 *
 * ProfileService is resolved from the container with a FrozenClock and a
 * SequenceIdGenerator bound in place of the production SystemClock and
 * UlidGenerator, so the 24-hour email-change window is decided from the injected
 * Clock alone and never from wall-clock time (the window is exercised by
 * advancing the FrozenClock, never by waiting). Each generated input runs
 * against the real database inside a transaction rolled back afterwards, as the
 * Testing Strategy prescribes for database-touching properties, so the inputs
 * are independent.
 *
 * "Sign-in behaviour keeps using the previous address" is observed through the
 * `users.email` / `users.email_lower` columns, which are exactly the columns the
 * Auth module's sign-in reads: an unconfirmed request leaves both unchanged, so
 * a sign-in would still resolve the account by the old address.
 */

use App\Modules\Account\Contracts\Services\ProfileService;
use App\Modules\Account\Domain\RequestEmailChangeCommand;
use App\Modules\Account\Models\EmailChangeToken;
use App\Modules\Account\Models\NotificationPreference;
use App\Modules\Account\Models\UserProfile;
use App\Modules\Auth\Models\User;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\Clock;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\IdGenerator;
use App\Shared\Support\SequenceIdGenerator;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(TestTrait::class, RefreshDatabase::class);

/**
 * The frozen instant the flow starts from; every deadline is measured relative
 * to this through the injected Clock.
 */
const EMAIL_CHANGE_PROPERTY_EPOCH = '2025-03-01T09:00:00Z';

const EMAIL_CHANGE_TTL_SECONDS = 24 * 60 * 60;

/**
 * Seeds one Customer with a known identity, phone, and two Notification
 * preferences, and returns the fields the property later asserts are preserved.
 *
 * @return array{
 *     id: string,
 *     email: string,
 *     emailLower: string,
 *     passwordHash: string,
 *     displayName: string,
 *     phone: ?string,
 *     preferences: list<array{category: string, channel: string, enabled: bool}>
 * }
 */
function seedEmailChangeCustomer(FrozenClock $clock, string $suffix, ?string $phone): array
{
    $now = $clock->nowUtc();
    $id = (string) Str::ulid();
    $email = "ada+{$suffix}@waselna.test";
    $emailLower = mb_strtolower($email);
    $passwordHash = '$2y$04$'.str_pad($suffix, 53, 'A');
    $displayName = "Ada {$suffix}";

    User::query()->create([
        'id' => $id,
        'email' => $email,
        'email_lower' => $emailLower,
        'password_hash' => $passwordHash,
        'display_name' => $displayName,
        'role' => 'customer',
        'account_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $profile = new UserProfile([
        'user_id' => $id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $profile->phone_encrypted = $phone;
    $profile->save();

    $preferences = [
        ['category' => 'order_status', 'channel' => 'email', 'enabled' => true],
        ['category' => 'delivery_update', 'channel' => 'in_app', 'enabled' => false],
    ];

    foreach ($preferences as $preference) {
        NotificationPreference::query()->create([
            'id' => (string) Str::ulid(),
            'user_id' => $id,
            'category' => $preference['category'],
            'channel' => $preference['channel'],
            'enabled' => $preference['enabled'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // Hold the expected preferences in the same (category, channel) order the
    // reader returns them, so an "unchanged" assertion compares like for like.
    usort(
        $preferences,
        static fn (array $a, array $b): int => [$a['category'], $a['channel']] <=> [$b['category'], $b['channel']],
    );

    return [
        'id' => $id,
        'email' => $email,
        'emailLower' => $emailLower,
        'passwordHash' => $passwordHash,
        'displayName' => $displayName,
        'phone' => $phone,
        'preferences' => $preferences,
    ];
}

/**
 * The Customer's current stored email, phone, hash, display name, and ordered
 * preferences, read straight from the tables so an assertion sees exactly what
 * was committed.
 *
 * @return array{
 *     email: string,
 *     emailLower: string,
 *     passwordHash: string,
 *     displayName: string,
 *     phone: ?string,
 *     preferences: list<array{category: string, channel: string, enabled: bool}>
 * }
 */
function readEmailChangeState(string $userId): array
{
    /** @var User $user */
    $user = User::query()->findOrFail($userId);
    $profile = UserProfile::query()->whereKey($userId)->first();

    $preferences = NotificationPreference::query()
        ->where('user_id', $userId)
        ->orderBy('category')
        ->orderBy('channel')
        ->get()
        ->map(static fn (NotificationPreference $p): array => [
            'category' => $p->category,
            'channel' => $p->channel,
            'enabled' => (bool) $p->enabled,
        ])
        ->all();

    return [
        'email' => (string) $user->email,
        'emailLower' => (string) $user->email_lower,
        'passwordHash' => (string) $user->password_hash,
        'displayName' => (string) $user->display_name,
        'phone' => $profile?->phone_encrypted,
        'preferences' => $preferences,
    ];
}

it('invalidates earlier tokens, preserves sign-in until confirmation, and replaces only the email', function (): void {
    $this->limitTo(100)->forAll(
        Generators::associative([
            // Two to four reissues before any confirmation; each must invalidate
            // the ones before it.
            'reissues' => Generators::choose(2, 4),
            // Which still-live token the Customer presents at confirmation: the
            // last reissue is the only live one, so only -1 should succeed.
            'presentOffsetFromLast' => Generators::choose(0, 3),
            // Seconds past issuance when confirmation is attempted, straddling
            // the 24-hour boundary.
            'confirmAfterSeconds' => Generators::oneOf(
                Generators::choose(0, EMAIL_CHANGE_TTL_SECONDS - 1),
                Generators::choose(EMAIL_CHANGE_TTL_SECONDS, EMAIL_CHANGE_TTL_SECONDS + 86_400),
            ),
            // Whether the Customer carries a stored phone, so "phone unchanged"
            // is checked both with and without one.
            'hasPhone' => Generators::bool(),
            'suffix' => Generators::elements(['01', '02', '03', '04']),
        ]),
    )->then(function (array $input): void {
        DB::beginTransaction();

        try {
            $clock = FrozenClock::at(EMAIL_CHANGE_PROPERTY_EPOCH);
            $ids = new SequenceIdGenerator();

            $this->app->instance(Clock::class, $clock);
            $this->app->instance(IdGenerator::class, $ids);

            /** @var ProfileService $service */
            $service = $this->app->make(ProfileService::class);

            $customer = seedEmailChangeCustomer(
                $clock,
                $input['suffix'],
                $input['hasPhone'] ? '+15550000000' : null,
            );

            // Issue a sequence of tokens, all at the same instant. Each issuance
            // must invalidate every token issued before it (Requirement 31.4).
            $plainTokens = [];
            for ($i = 0; $i < $input['reissues']; $i++) {
                $plainTokens[] = $service->requestEmailChange(new RequestEmailChangeCommand(
                    userId: $customer['id'],
                    newEmail: "ada.new{$i}+{$input['suffix']}@waselna.test",
                ));

                // After each issuance, exactly one token is live (unused): the
                // one just issued.
                $live = EmailChangeToken::query()
                    ->where('user_id', $customer['id'])
                    ->whereNull('used_at')
                    ->count();
                expect($live)->toBe(1);
            }

            // The stored email and sign-in columns are untouched by issuing
            // tokens: a sign-in would still find the account by the old address
            // (Requirement 31.13).
            $afterIssue = readEmailChangeState($customer['id']);
            expect($afterIssue['email'])->toBe($customer['email'])
                ->and($afterIssue['emailLower'])->toBe($customer['emailLower']);

            // Choose which token to present. Offset 0 means "the only live one",
            // the last reissue; larger offsets reach back into already
            // invalidated tokens, which must all be rejected.
            $lastIndex = $input['reissues'] - 1;
            $presentIndex = $lastIndex - $input['presentOffsetFromLast'];
            $presentsLiveToken = $presentIndex === $lastIndex;
            $tokenIndex = max(0, $presentIndex);
            $presentedPlain = $plainTokens[$tokenIndex];
            $targetEmail = "ada.new{$tokenIndex}+{$input['suffix']}@waselna.test";

            // Advance the clock to the chosen confirmation instant; this is the
            // only thing that decides whether the token is within its 24-hour
            // window (Requirement 31.13), and it comes from the injected Clock.
            $clock->advanceSeconds($input['confirmAfterSeconds']);
            $withinWindow = $input['confirmAfterSeconds'] < EMAIL_CHANGE_TTL_SECONDS;

            $shouldSucceed = $presentsLiveToken && $withinWindow;

            if ($shouldSucceed) {
                $view = $service->confirmEmailChange($customer['id'], $presentedPlain);

                $after = readEmailChangeState($customer['id']);

                // Exactly the email address changed (Requirement 31.12).
                expect($after['email'])->toBe($targetEmail)
                    ->and($after['emailLower'])->toBe(mb_strtolower($targetEmail))
                    ->and($view->email)->toBe($targetEmail)
                    // Password hash, display name, phone, and preferences are
                    // byte-unchanged.
                    ->and($after['passwordHash'])->toBe($customer['passwordHash'])
                    ->and($after['displayName'])->toBe($customer['displayName'])
                    ->and($after['phone'])->toBe($customer['phone'])
                    ->and($after['preferences'])->toBe($customer['preferences']);

                // The accepted token is now spent: presenting it again is
                // rejected and changes nothing (single use).
                $replay = static fn (): mixed => $service->confirmEmailChange($customer['id'], $presentedPlain);
                expect($replay)->toThrow(ValidationException::class);
                expect(readEmailChangeState($customer['id'])['email'])->toBe($targetEmail);

                return;
            }

            // Every rejection path — an invalidated earlier token or an expired
            // live token — fails with `invalid_confirmation_token` and leaves the
            // stored email address unchanged (Requirement 31.13).
            try {
                $service->confirmEmailChange($customer['id'], $presentedPlain);
                $this->fail('Expected the confirmation to be rejected.');
            } catch (ValidationException $e) {
                expect($e->errorCode())->toBe('invalid_confirmation_token');
            }

            $afterReject = readEmailChangeState($customer['id']);
            expect($afterReject['email'])->toBe($customer['email'])
                ->and($afterReject['emailLower'])->toBe($customer['emailLower'])
                ->and($afterReject['passwordHash'])->toBe($customer['passwordHash'])
                ->and($afterReject['displayName'])->toBe($customer['displayName'])
                ->and($afterReject['phone'])->toBe($customer['phone'])
                ->and($afterReject['preferences'])->toBe($customer['preferences']);
        } finally {
            DB::rollBack();
            $this->app->forgetInstance(Clock::class);
            $this->app->forgetInstance(IdGenerator::class);
        }
    });
});
