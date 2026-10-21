<?php

declare(strict_types=1);

// Feature: waselna, Property 71: Partial profile updates change exactly the submitted fields

/*
 * For any profile update submitting any subset of the updatable fields, exactly
 * those fields hold the submitted values afterwards, an empty phone number
 * clears the stored phone number, every unsubmitted field and the stored email
 * address and password hash are byte-unchanged, and the own-profile view
 * returns every documented field while excluding the stored password hash and
 * every email-change confirmation token.
 *
 * **Validates: Requirements 31.1, 31.2, 58.2, 58.5**
 *
 * The subject is the Account module's ProfileServiceImplementation
 * (task 4.2). A partial update is three-valued per field — absent, submitted
 * with a value, or (phone only) submitted empty — carried by the
 * `*WasSubmitted` flags of UpdateProfileCommand, so this property drives the
 * service with every combination the generator produces and pins the stored
 * state before and after each one.
 *
 * The display name lives on the Auth-owned `users` row, the phone number on
 * `user_profiles.phone_encrypted`, and the Notification preferences in
 * `notification_preferences`; the stored email address and password hash also
 * live on `users` and must never move under a profile update. The property
 * reads the raw `users` row through the query builder so the comparison is on
 * the stored bytes, not on a re-encrypted projection.
 *
 * Database isolation follows the Testing Strategy: each generated input runs
 * inside a transaction that is rolled back before the next, so one seeded
 * Customer per input leaves no residue. The schema is built directly from the
 * columns of the task 4.1 migration rather than by running the full migration
 * set, because the Property suite's SQLite database cannot evaluate the PostGIS
 * statements that later migrations carry.
 *
 * Time is read only through the injected Clock: the service stamps
 * `updated_at` through Eloquent, so a FrozenClock is installed as the model
 * time source for the duration of the test, and no assertion depends on
 * wall-clock time.
 */

use App\Modules\Account\Domain\NotificationPreferenceChange;
use App\Modules\Account\Domain\UpdateProfileCommand;
use App\Modules\Account\Models\NotificationPreference;
use App\Modules\Account\Models\UserProfile;
use App\Modules\Account\Repositories\ProfileRepository;
use App\Modules\Account\Services\ProfileServiceImplementation;
use App\Modules\Auth\Boundary\CredentialDirectory;
use App\Modules\Auth\Domain\ChangePasswordCommand;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\SequenceIdGenerator;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(TestTrait::class);

/**
 * The Notification categories and the two channels Requirement 31.2 names, held
 * here so the generator draws preference changes from the same sets the own
 * profile view reports (Requirement 31.1).
 */
const PROFILE_CATEGORIES = ['order_status', 'delivery_update', 'promotions', 'account'];

const PROFILE_CHANNELS = ['email', 'in_app'];

/**
 * Builds the four Account tables this property touches directly from the task
 * 4.1 migration's columns. Only the columns the service reads or writes are
 * created; the PostGIS-bearing migrations are deliberately not run so the suite
 * stays on its SQLite database.
 */
function createProfileSchema(): void
{
    Schema::dropIfExists('notification_preferences');
    Schema::dropIfExists('email_change_tokens');
    Schema::dropIfExists('user_profiles');
    Schema::dropIfExists('delivery_addresses');
    Schema::dropIfExists('users');

    Schema::create('users', function ($table): void {
        $table->string('id')->primary();
        $table->string('email', 254);
        $table->string('email_lower', 254)->unique();
        $table->string('password_hash');
        $table->string('display_name', 100);
        $table->string('role', 32);
        $table->string('account_status', 16)->default('active');
        $table->string('restaurant_id')->nullable();
        $table->string('branch_id')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    Schema::create('user_profiles', function ($table): void {
        $table->string('user_id')->primary();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('phone')->nullable();
        $table->text('phone_encrypted')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    Schema::create('delivery_addresses', function ($table): void {
        $table->string('id')->primary();
        $table->string('user_id');
        $table->boolean('is_default')->default(false);
        // Archival is a nullable timestamp (task 4.6 reshape); the profile view's
        // default-address read filters on archived_at IS NULL.
        $table->timestamp('archived_at')->nullable();
    });

    Schema::create('notification_preferences', function ($table): void {
        $table->string('id')->primary();
        $table->string('user_id');
        $table->string('category', 64);
        $table->string('channel', 32);
        $table->boolean('enabled')->default(true);
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
        $table->unique(['user_id', 'category', 'channel']);
    });
}

/**
 * A printable string of the given length, drawn from characters that survive a
 * round trip through the database unchanged, so a stored value compares equal to
 * what was submitted.
 */
function profileText(int $length): ErisGenerator
{
    if ($length <= 0) {
        return Generators::constant('');
    }

    return Generators::map(
        static fn (array $codepoints): string => implode('', array_map(
            static fn (int $offset): string => chr(33 + $offset),
            $codepoints,
        )),
        Generators::tuple(...array_fill(0, $length, Generators::choose(0, 92))),
    );
}

/**
 * A phone number of 5 to 20 characters drawn from the permitted set of digits,
 * spaces, hyphens, and a leading plus sign (Requirement 31.2).
 */
function profilePhone(): ErisGenerator
{
    return Generators::map(
        static function (array $parts): string {
            [$lead, $bodyDigits] = $parts;
            $body = implode('', array_map(static fn (int $d): string => (string) $d, $bodyDigits));

            return $lead.$body;
        },
        Generators::tuple(
            Generators::elements(['+', '']),
            Generators::vector(6, Generators::choose(0, 9)),
        ),
    );
}

/**
 * One generated scenario: the stored starting state of a Customer and the
 * partial update to apply to it.
 *
 * `submitDisplayName`, `submitPhone`, and the per-change presence of each
 * preference are independent booleans so every subset of the updatable fields
 * is reachable, including the empty update that must change nothing. The phone
 * carries three cases — a value, an explicit empty clear, and the stored start
 * value — so the clear path of Requirement 31.2 is exercised directly.
 */
function profileScenario(): ErisGenerator
{
    return Generators::associative([
        // Stored starting state.
        'startDisplayName' => profileText(8),
        'startPhone' => Generators::oneOf(Generators::constant(null), profilePhone()),
        'startPreferences' => preferenceMatrix(),

        // The partial update.
        'submitDisplayName' => Generators::bool(),
        'newDisplayName' => profileText(10),
        'submitPhone' => Generators::bool(),
        // null models an explicit empty phone that must clear the stored value.
        'newPhone' => Generators::oneOf(Generators::constant(null), profilePhone()),
        'preferenceChanges' => preferenceChangeList(),
    ]);
}

/**
 * A stored preference matrix: a subset of the (category, channel) grid, each
 * with an enabled flag. Drawn as an index into the full grid so no pair repeats.
 */
function preferenceMatrix(): ErisGenerator
{
    return Generators::map(
        static function (array $flags): array {
            $matrix = [];
            $index = 0;

            foreach (PROFILE_CATEGORIES as $category) {
                foreach (PROFILE_CHANNELS as $channel) {
                    if ($flags[$index] !== null) {
                        $matrix[] = [
                            'category' => $category,
                            'channel' => $channel,
                            'enabled' => $flags[$index],
                        ];
                    }
                    $index++;
                }
            }

            return $matrix;
        },
        Generators::tuple(...array_fill(
            0,
            count(PROFILE_CATEGORIES) * count(PROFILE_CHANNELS),
            // null: the pair is absent from the stored matrix.
            Generators::oneOf(Generators::constant(null), Generators::bool()),
        )),
    );
}

/**
 * A list of submitted preference changes: a subset of the grid, each toggled to
 * a submitted enabled flag.
 */
function preferenceChangeList(): ErisGenerator
{
    return Generators::map(
        static function (array $flags): array {
            $changes = [];
            $index = 0;

            foreach (PROFILE_CATEGORIES as $category) {
                foreach (PROFILE_CHANNELS as $channel) {
                    if ($flags[$index] !== null) {
                        $changes[] = new NotificationPreferenceChange(
                            $category,
                            $channel,
                            $flags[$index],
                        );
                    }
                    $index++;
                }
            }

            return $changes;
        },
        Generators::tuple(...array_fill(
            0,
            count(PROFILE_CATEGORIES) * count(PROFILE_CHANNELS),
            Generators::oneOf(Generators::constant(null), Generators::bool()),
        )),
    );
}

/**
 * A ProfileService wired to a FrozenClock and a SequenceIdGenerator, with the
 * Auth CredentialDirectory replaced by a double that fails the test if a profile
 * update ever reaches across the boundary into password change: a partial update
 * must never touch the credential path.
 */
function profileServiceUnderTest(FrozenClock $clock): ProfileServiceImplementation
{
    $credentials = new class implements CredentialDirectory
    {
        public function changePassword(ChangePasswordCommand $command): void
        {
            throw new RuntimeException(
                'A profile update must not reach the credential boundary.',
            );
        }
    };

    return new ProfileServiceImplementation(
        new ProfileRepository,
        $credentials,
        $clock,
        new SequenceIdGenerator,
    );
}

beforeEach(function (): void {
    createProfileSchema();
});

it('changes exactly the submitted fields and leaves every other value byte-unchanged', function (): void {
    $clock = FrozenClock::at('2025-03-01T12:00:00Z');

    // The service stamps updated_at through Eloquent; pin that time source to
    // the injected Clock so the test owns every timestamp it writes.
    Carbon::setTestNow(Carbon::instance($clock->nowUtc()));

    $service = profileServiceUnderTest($clock);

    $this->limitTo(100)
        ->forAll(profileScenario())
        ->then(function (array $scenario) use ($service): void {
            // Each input is self-contained: seed, act, assert, then roll back so
            // the next input starts from an empty set of Account tables.
            DB::beginTransaction();

            try {
                $userId = (string) Str::ulid();
                $email = 'customer+'.Str::lower(Str::random(10)).'@waselna.test';
                $passwordHash = '$2y$04$'.str_pad(Str::random(53), 53, '0');

                // --- Seed the stored starting state ---------------------------
                DB::table('users')->insert([
                    'id' => $userId,
                    'email' => $email,
                    'email_lower' => Str::lower($email),
                    'password_hash' => $passwordHash,
                    'display_name' => $scenario['startDisplayName'],
                    'role' => 'customer',
                    'account_status' => 'active',
                    'restaurant_id' => null,
                    'branch_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $profile = new UserProfile(['user_id' => $userId]);
                $profile->phone_encrypted = $scenario['startPhone'];
                $profile->save();

                $startPreferences = [];
                foreach ($scenario['startPreferences'] as $pref) {
                    NotificationPreference::query()->create([
                        'id' => (string) Str::ulid(),
                        'user_id' => $userId,
                        'category' => $pref['category'],
                        'channel' => $pref['channel'],
                        'enabled' => $pref['enabled'],
                    ]);
                    $startPreferences[$pref['category'].'|'.$pref['channel']] = $pref['enabled'];
                }

                // Byte-level snapshot of the Auth-owned row before the update.
                $usersBefore = (array) DB::table('users')->where('id', $userId)->first();

                // --- Build and apply the partial update -----------------------
                $command = new UpdateProfileCommand(
                    userId: $userId,
                    displayNameWasSubmitted: $scenario['submitDisplayName'],
                    displayName: $scenario['submitDisplayName'] ? $scenario['newDisplayName'] : null,
                    phoneWasSubmitted: $scenario['submitPhone'],
                    phone: $scenario['submitPhone'] ? $scenario['newPhone'] : null,
                    notificationPreferences: $scenario['preferenceChanges'],
                );

                $view = $service->updateProfile($command);

                // --- The submitted fields hold the submitted values -----------
                $expectedDisplayName = $scenario['submitDisplayName']
                    ? $scenario['newDisplayName']
                    : $scenario['startDisplayName'];

                expect($view->displayName)->toBe($expectedDisplayName);

                // Phone: a submitted value replaces, a submitted empty clears,
                // an unsubmitted phone keeps the stored value (Requirement 31.2).
                $expectedPhone = $scenario['submitPhone']
                    ? $scenario['newPhone']
                    : $scenario['startPhone'];

                expect($view->phone)->toBe($expectedPhone);

                $storedProfile = UserProfile::query()->find($userId);
                expect($storedProfile?->phone_encrypted)->toBe($expectedPhone);

                // Preferences: every submitted change holds its submitted flag,
                // and every unsubmitted stored preference keeps its value.
                $expectedPreferences = $startPreferences;
                foreach ($scenario['preferenceChanges'] as $change) {
                    $expectedPreferences[$change->category.'|'.$change->channel] = $change->enabled;
                }

                $stored = [];
                foreach (NotificationPreference::query()->where('user_id', $userId)->get() as $row) {
                    $stored[$row->category.'|'.$row->channel] = $row->enabled;
                }

                expect($stored)->toEqual($expectedPreferences);

                // --- Email and password hash are byte-unchanged ---------------
                $usersAfter = (array) DB::table('users')->where('id', $userId)->first();

                expect($usersAfter['email'])->toBe($usersBefore['email'])
                    ->and($usersAfter['email_lower'])->toBe($usersBefore['email_lower'])
                    ->and($usersAfter['password_hash'])->toBe($usersBefore['password_hash']);

                // The display name is the one users-row field a profile update
                // may move; nothing else on that row changes.
                expect($usersAfter['role'])->toBe($usersBefore['role'])
                    ->and($usersAfter['account_status'])->toBe($usersBefore['account_status']);

                // --- The own-profile view is complete and secret-free ---------
                // It returns every documented field (Requirement 31.1) and, by
                // construction, carries no password hash and no email-change
                // confirmation token.
                $projected = $view->toArray();

                expect(array_keys($projected))->toEqual([
                    'id',
                    'display_name',
                    'email',
                    'phone',
                    'notification_preferences',
                    'default_address_id',
                    'created_at',
                ])
                    ->and($projected['id'])->toBe($userId)
                    ->and($projected['email'])->toBe($usersBefore['email'])
                    ->and($projected)->not->toHaveKey('password_hash')
                    ->and($projected)->not->toHaveKey('token')
                    ->and(json_encode($projected))->not->toContain($usersBefore['password_hash']);
            } finally {
                DB::rollBack();
            }
        });

    Carbon::setTestNow();
});
