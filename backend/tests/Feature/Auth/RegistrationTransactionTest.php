<?php

declare(strict_types=1);

// Feature: waselna, task 4.8: wire the registration transaction across Auth and Account.

/*
 * Example-based service tests for the registration transaction (task 4.8;
 * Requirements 1.1, 16.12, 40.8, 40.9). The AuthenticationService::register call
 * must create the Auth-owned `users` row together with the Account-owned
 * `user_profiles` row and the ten `notification_preferences` rows (five
 * Notification categories times the two channels) inside one transaction, so a
 * failure in either half leaves no partial Platform user or profile behind.
 *
 * These tests pin the behaviours a reader of the design's "Transaction
 * boundaries" row expects:
 *
 *  - a successful registration leaves exactly one users row, one user_profiles
 *    row, and ten notification_preferences rows all defaulting to enabled
 *    (Requirements 1.1, 16.12);
 *  - a failure while provisioning the Account records rolls the whole thing
 *    back, leaving zero users and zero profile rows (Requirements 40.8, 40.9).
 *
 * The real ProfileServiceImplementation is wired behind the AccountProvisioning
 * boundary so the test exercises the actual cross-module transaction rather than
 * a stand-in. The three tables are built directly from the owning migration's
 * columns, matching the Account suite's SQLite approach; identifiers come from a
 * SequenceIdGenerator and time from a FrozenClock so the writes are
 * deterministic.
 */

use App\Modules\Account\Boundary\AccountProvisioning;
use App\Modules\Account\Repositories\ProfileRepository;
use App\Modules\Account\Services\ProfileServiceImplementation;
use App\Modules\Auth\Boundary\CredentialDirectory;
use App\Modules\Auth\Domain\ChangePasswordCommand;
use App\Modules\Auth\Services\AuthenticationService;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\Clock;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\IdGenerator;
use App\Shared\Support\SequenceIdGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function registrationProfileService(IdGenerator $ids, Clock $clock): ProfileServiceImplementation
{
    // The AccountProvisioning boundary never reaches the CredentialDirectory
    // during provisioning, so an always-throwing double stands in for Auth's
    // password-change seam that ProfileServiceImplementation also depends on.
    $credentials = new class implements CredentialDirectory
    {
        public function changePassword(ChangePasswordCommand $command): void
        {
            throw new LogicException('not used in registration');
        }
    };

    return new ProfileServiceImplementation(
        new ProfileRepository,
        $credentials,
        $clock,
        $ids,
    );
}

function authServiceUnderTest(?AccountProvisioning $provisioning = null): AuthenticationService
{
    $ids = new SequenceIdGenerator;
    $clock = FrozenClock::at('2025-03-01T12:00:00Z');

    return new AuthenticationService(
        $clock,
        $ids,
        $provisioning ?? registrationProfileService($ids, $clock),
    );
}

function createRegistrationSchema(): void
{
    Schema::dropIfExists('notification_preferences');
    Schema::dropIfExists('user_profiles');
    Schema::dropIfExists('users');

    Schema::create('users', function ($table): void {
        $table->string('id')->primary();
        $table->string('email', 254);
        $table->string('email_lower', 254)->unique();
        $table->string('password_hash');
        $table->string('display_name', 100)->nullable();
        $table->string('role', 32)->default('customer');
        $table->string('account_status', 32)->default('active');
        $table->string('restaurant_id')->nullable();
        $table->string('branch_id')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });

    Schema::create('user_profiles', function ($table): void {
        $table->string('user_id')->primary();
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->text('phone_encrypted')->nullable();
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
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

beforeEach(function (): void {
    createRegistrationSchema();
});

it('creates the user, profile, and ten enabled notification preferences in one transaction', function (): void {
    $auth = authServiceUnderTest();

    $result = $auth->register('Customer@Waselna.Test', 'a-strong-password', 'A Customer');

    expect($result['role'])->toBe('customer')
        ->and($result['email'])->toBe('Customer@Waselna.Test')
        // The response never echoes the submitted password or any hash.
        ->and($result)->not->toHaveKey('password')
        ->and($result)->not->toHaveKey('password_hash');

    $userId = $result['id'];

    expect(DB::table('users')->count())->toBe(1)
        ->and(DB::table('users')->where('id', $userId)->where('email_lower', 'customer@waselna.test')->exists())->toBeTrue()
        ->and(DB::table('user_profiles')->where('user_id', $userId)->count())->toBe(1);

    // Exactly ten preference rows: five categories times two channels.
    $preferences = DB::table('notification_preferences')->where('user_id', $userId)->get();
    expect($preferences)->toHaveCount(10);

    // Every default pair is enabled (Requirement 16.12), one per category/channel.
    $categories = ['order_status', 'delivery_status', 'payment', 'promotion', 'account'];
    $channels = ['email', 'in_app'];
    foreach ($categories as $category) {
        foreach ($channels as $channel) {
            $row = DB::table('notification_preferences')
                ->where('user_id', $userId)
                ->where('category', $category)
                ->where('channel', $channel)
                ->first();
            expect($row)->not->toBeNull();
            assert($row !== null);
            expect((bool) $row->enabled)->toBeTrue();
        }
    }
});

it('rolls back the user when Account provisioning fails, leaving nothing behind', function (): void {
    // A provisioning double that throws after the users row is written forces
    // the transaction to roll back (Requirements 40.8, 40.9).
    $failing = new class implements AccountProvisioning
    {
        public function provisionCustomerProfile(string $userId): void
        {
            throw new RuntimeException('provisioning failed');
        }
    };

    $auth = authServiceUnderTest($failing);

    expect(fn () => $auth->register('customer@waselna.test', 'a-strong-password', 'A Customer'))
        ->toThrow(RuntimeException::class);

    // No partial Platform user or profile survives the failed registration.
    expect(DB::table('users')->count())->toBe(0)
        ->and(DB::table('user_profiles')->count())->toBe(0)
        ->and(DB::table('notification_preferences')->count())->toBe(0);
});

it('rejects a duplicate email case-insensitively without creating a second user', function (): void {
    $auth = authServiceUnderTest();

    $auth->register('customer@waselna.test', 'a-strong-password', 'A Customer');

    expect(fn () => $auth->register('CUSTOMER@WASELNA.TEST', 'another-password', 'Someone Else'))
        ->toThrow(ValidationException::class);

    expect(DB::table('users')->count())->toBe(1)
        ->and(DB::table('user_profiles')->count())->toBe(1)
        ->and(DB::table('notification_preferences')->count())->toBe(10);
});
