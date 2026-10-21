<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Feature: food-delivery-platform, Property 73: Password change succeeds only
| against the stored hash
|--------------------------------------------------------------------------
|
| *For any* password change request, the stored hash is replaced exactly when
| the submitted current password matches the stored hash and the new password
| is 12-128 characters; a mismatch yields `current_password_mismatch` and an
| out-of-range new password yields `validation_failed`, in both cases leaving
| the stored hash unchanged and invalidating no Refresh_Token.
|
| Validates: Requirements 31.5, 31.6
|
| The flow runs through the Account module's ProfileService, which delegates
| across the Auth CredentialDirectory boundary (Requirements 31.5, 31.6); the
| test resolves the Service_Interface from the container so the real boundary
| wiring is exercised. All time is read through an injected FrozenClock bound
| in place of the system Clock, never wall-clock time, so Refresh_Token
| revocation timestamps are deterministic. Each generated input runs inside a
| database transaction rolled back afterward, as the Testing Strategy requires
| for database-touching properties, against the real PostgreSQL the strategy
| names for the property suite (the users/refresh_tokens CHECK constraints
| cannot be expressed on SQLite).
*/

use App\Modules\Account\Contracts\Services\ProfileService;
use App\Modules\Auth\Domain\ChangePasswordCommand;
use App\Modules\Auth\Models\RefreshToken;
use App\Modules\Auth\Models\User;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\Clock;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\IdGenerator;
use Eris\Generator as ErisGenerator;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(TestTrait::class);

/**
 * The instant the FrozenClock is held at for every input. Any UTC instant will
 * do; revocation only needs a stable, non-wall-clock timestamp.
 */
const PASSWORD_CHANGE_CLOCK_INSTANT = '2025-06-01T12:00:00Z';

/**
 * The password length bounds of Requirement 31.5/31.6: a new password is in
 * range at 12..128 characters inclusive and out of range otherwise.
 */
const PASSWORD_MIN = 12;

const PASSWORD_MAX = 128;

/**
 * The real PostgreSQL connection the property suite runs against, or null when
 * none is reachable.
 *
 * The suite's phpunit.xml forces SQLite, which cannot express the `users` role
 * and account_status CHECK constraints or the `refresh_tokens` schema the flow
 * exercises, so this resolves a `pgsql` connection pointed at the running
 * PostgreSQL container (the Local_Dev_Environment of Requirement 24), mirroring
 * the Zone containment agreement test. Host and credentials come from the
 * environment when set and otherwise fall back to the container defaults; a
 * probe query confirms the schema is present before the connection is used.
 */
function passwordChangeConnectionOrNull(): ?Connection
{
    static $resolved = false;
    static $connection = null;

    if ($resolved) {
        return $connection;
    }

    $resolved = true;

    // The suite's phpunit.xml forces DB_* env to SQLite test values
    // (DB_PASSWORD=testing, etc.), so the connection is pinned to the
    // Local_Dev_Environment defaults of docker-compose rather than read from
    // env; a WASELNA_TEST_PG_* override allows a non-default deployment.
    $database = env('WASELNA_TEST_PG_DATABASE', 'waselna');
    $username = env('WASELNA_TEST_PG_USERNAME', 'waselna');
    $password = env('WASELNA_TEST_PG_PASSWORD', 'waselna_local_password');

    $candidates = [
        // From the host the published port maps the container.
        ['host' => '127.0.0.1', 'port' => 5432],
        // Inside the backend container the service is reachable by name.
        ['host' => 'postgres', 'port' => 5432],
    ];

    foreach ($candidates as $candidate) {
        config([
            'database.connections.pgsql_password_change' => [
                'driver' => 'pgsql',
                'host' => $candidate['host'],
                'port' => $candidate['port'],
                'database' => $database,
                'username' => $username,
                'password' => $password,
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
        ]);

        DB::purge('pgsql_password_change');

        try {
            $pending = DB::connection('pgsql_password_change');
            // Confirm the tables the flow writes are present.
            $pending->select('SELECT 1 FROM users LIMIT 1');
            $pending->select('SELECT 1 FROM refresh_tokens LIMIT 1');

            $connection = $pending;

            return $connection;
        } catch (\Throwable) {
            DB::purge('pgsql_password_change');

            continue;
        }
    }

    return null;
}

/**
 * A printable password string of exactly $length characters.
 *
 * The alphabet is restricted to printable ASCII so a shrunk counterexample is
 * readable; length is what the rule turns on, so content beyond length is
 * irrelevant to the property.
 */
function passwordOfLength(int $length): ErisGenerator
{
    $alphabet = array_map('chr', range(33, 126));

    if ($length === 0) {
        return Generators::constant('');
    }

    return Generators::map(
        static fn (array $chars): string => implode('', $chars),
        Generators::vector($length, Generators::elements($alphabet)),
    );
}

/**
 * A new-password candidate drawn across the length bounds: the two in-range
 * edges (12 and 128), representative in-range lengths, and the two just-out-of
 * range lengths (11 and 129) plus the empty string, so both the accept and the
 * `validation_failed` branches are reached.
 */
function newPasswordCandidate(): ErisGenerator
{
    return Generators::bind(
        Generators::elements([0, 11, PASSWORD_MIN, 20, 64, PASSWORD_MAX, PASSWORD_MAX + 1]),
        static fn (int $length): ErisGenerator => passwordOfLength($length),
    );
}

/**
 * Seeds one active customer whose stored hash is the adaptive hash of
 * $currentPassword, together with $sessionCount live Refresh_Tokens plus one
 * nominated as the session presented with the request.
 *
 * A unique token suffix keeps the SHA-256 `token_hash` values distinct across
 * inputs even though every input is rolled back.
 *
 * @return array{user: User, presentedPlain: string}
 */
function seedCustomerWithSessions(IdGenerator $ids, Clock $clock, string $currentPassword, int $sessionCount, string $unique): array
{
    $user = new User([
        'id' => $ids->ulid(),
        'email' => $unique.'@waselna.local',
        'email_lower' => $unique.'@waselna.local',
        'password_hash' => Hash::make($currentPassword),
        'display_name' => 'Pat Customer',
        'role' => 'customer',
        'account_status' => 'active',
    ]);
    $user->save();

    $now = $clock->nowUtc();
    $presentedPlain = 'presented-'.$unique;

    // The presented session: its hash matches what the command carries, so it
    // must survive a successful change.
    (new RefreshToken([
        'id' => $ids->ulid(),
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $presentedPlain),
        'expires_at' => $now->modify('+30 days'),
        'created_at' => $now,
    ]))->save();

    for ($i = 0; $i < $sessionCount; $i++) {
        (new RefreshToken([
            'id' => $ids->ulid(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'other-'.$unique.'-'.$i),
            'expires_at' => $now->modify('+30 days'),
            'created_at' => $now,
        ]))->save();
    }

    return ['user' => $user, 'presentedPlain' => $presentedPlain];
}

test('Property 73: password change succeeds only against the stored hash', function (): void {
    $connection = passwordChangeConnectionOrNull();

    if ($connection === null) {
        $this->markTestSkipped('A PostgreSQL database with the Delivery_API schema is required for the password-change property.');
    }

    // Point Eloquent's default connection at the real PostgreSQL so the models
    // the service writes resolve there.
    config(['database.default' => 'pgsql_password_change']);
    DB::setDefaultConnection('pgsql_password_change');

    // Time is read through the injected Clock only; freeze it so revocation
    // timestamps are deterministic and never wall-clock time.
    $clock = FrozenClock::at(PASSWORD_CHANGE_CLOCK_INSTANT);
    $this->app->instance(Clock::class, $clock);

    /** @var IdGenerator $ids */
    $ids = $this->app->make(IdGenerator::class);

    $this->limitTo(100)->forAll(
        Generators::associative([
            // The password that actually matches the stored hash.
            'currentPassword' => passwordOfLength(16),
            // Whether the request submits the real current password or a wrong one.
            'currentMatches' => Generators::bool(),
            // The new password, drawn across the length bounds.
            'newPassword' => newPasswordCandidate(),
            // How many other live sessions exist besides the presented one.
            'otherSessions' => Generators::choose(0, 4),
            // Whether the request carries the presented Refresh_Token.
            'presentsToken' => Generators::bool(),
        ]),
    )->then(function (array $input) use ($clock, $ids): void {
        DB::beginTransaction();

        try {
            /** @var ProfileService $service */
            $service = $this->app->make(ProfileService::class);

            $unique = strtolower((string) $ids->ulid());
            $seed = seedCustomerWithSessions($ids, $clock, $input['currentPassword'], $input['otherSessions'], $unique);
            /** @var User $user */
            $user = $seed['user'];
            $storedHashBefore = $user->password_hash;
            $liveBefore = RefreshToken::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->pluck('id')
                ->all();
            sort($liveBefore);

            $newLength = mb_strlen($input['newPassword']);
            $newInRange = $newLength >= PASSWORD_MIN && $newLength <= PASSWORD_MAX;

            $submittedCurrent = $input['currentMatches']
                ? $input['currentPassword']
                : $input['currentPassword'].'-wrong';

            $command = new ChangePasswordCommand(
                userId: $user->id,
                currentPassword: $submittedCurrent,
                newPassword: $input['newPassword'],
                presentedRefreshToken: $input['presentsToken'] ? $seed['presentedPlain'] : null,
            );

            // The implementation checks the new-password length before the
            // current-password match, so an out-of-range new password yields
            // `validation_failed` regardless of whether the current matches.
            $expectSuccess = $newInRange && $input['currentMatches'];
            $expectedCode = $newInRange
                ? ($input['currentMatches'] ? null : 'current_password_mismatch')
                : 'validation_failed';

            $thrownCode = null;

            try {
                $service->changePassword($command);
            } catch (ValidationException $e) {
                $thrownCode = $e->errorCode();
            }

            $user->refresh();
            $liveAfter = RefreshToken::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->pluck('id')
                ->all();
            sort($liveAfter);

            if ($expectSuccess) {
                // Success: no error, the stored hash is replaced and verifies
                // against the new password, and every Refresh_Token except the
                // presented one is revoked (Requirement 31.5).
                expect($thrownCode)->toBeNull();
                expect($user->password_hash)->not->toBe($storedHashBefore);
                expect(Hash::check($input['newPassword'], $user->password_hash))->toBeTrue();

                $presentedId = RefreshToken::query()
                    ->where('user_id', $user->id)
                    ->where('token_hash', hash('sha256', $seed['presentedPlain']))
                    ->value('id');

                if ($input['presentsToken']) {
                    // The presented session survives; no other session does.
                    expect($liveAfter)->toBe([$presentedId]);
                } else {
                    // No session was presented, so every session is revoked.
                    expect($liveAfter)->toBe([]);
                }
            } else {
                // Rejection: the documented code, the stored hash untouched,
                // and not a single Refresh_Token revoked (Requirement 31.6).
                expect($thrownCode)->toBe($expectedCode);
                expect($user->password_hash)->toBe($storedHashBefore);
                expect(Hash::check($input['currentPassword'], $user->password_hash))->toBeTrue();
                expect($liveAfter)->toBe($liveBefore);
            }
        } finally {
            DB::rollBack();
        }
    });
});
