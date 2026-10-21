<?php

declare(strict_types=1);

// Feature: waselna, task 12.1: Platform_Configuration storage, write service, and the read-only reader boundary.

/*
 * Example-based tests for the Admin module's PlatformConfigurationServiceImplementation
 * (task 12.1; Requirements 37.9, 37.10, 16.12). The property that every request
 * after a commit computes from the new values is design Property 56, written
 * with the Pricing/Dispatch integration later; these tests pin the behaviours a
 * reader of the requirements expects of storage and the two seams:
 *
 *  - with no row written, every reader accessor returns the Platform defaults
 *    (single-row configuration semantics with sensible defaults);
 *  - an accepted update persists the single row and every reader accessor and
 *    the Administrator snapshot reflect the new values (Requirement 37.9);
 *  - an out-of-range submission is rejected with validation_failed and leaves
 *    the stored row byte-unchanged (Requirement 37.10);
 *  - repeated updates keep exactly one row (single-row semantics).
 *
 * The one platform_configuration table is built directly from the running
 * migration's columns rather than by running the full migration set, matching
 * the Account and Zone suites' SQLite approach. Time comes from a FrozenClock
 * and identifiers from a SequenceIdGenerator so a first-ever write is
 * deterministic.
 */

use App\Modules\Admin\Domain\PlatformConfigurationDefaults;
use App\Modules\Admin\Domain\UpdateConfigurationCommand;
use App\Modules\Admin\Repositories\PlatformConfigurationRepository;
use App\Modules\Admin\Services\PlatformConfigurationServiceImplementation;
use App\Shared\Domain\Exceptions\ValidationException;
use App\Shared\Support\FrozenClock;
use App\Shared\Support\SequenceIdGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function platformConfigurationServiceUnderTest(): PlatformConfigurationServiceImplementation
{
    return new PlatformConfigurationServiceImplementation(
        new PlatformConfigurationRepository,
        FrozenClock::at('2025-03-01T12:00:00Z'),
        new SequenceIdGenerator,
    );
}

function createPlatformConfigurationSchema(): void
{
    Schema::dropIfExists('platform_configuration');

    Schema::create('platform_configuration', function ($table): void {
        $table->string('id')->primary();
        $table->integer('service_fee_percentage_min_bp')->default(500);
        $table->integer('service_fee_percentage_max_bp')->default(1500);
        $table->integer('delivery_fee_min_minor')->default(0);
        $table->integer('delivery_fee_max_minor')->default(5000);
        $table->integer('offer_radius_km_default')->default(5);
        $table->integer('offer_radius_km_max')->default(20);
        $table->json('rejection_reasons')->nullable();
        $table->json('notification_defaults')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
}

/**
 * A fully valid command whose values differ from the defaults, so a test can
 * assert the stored values are the submitted ones and not the fallbacks.
 */
function validConfigurationCommand(): UpdateConfigurationCommand
{
    return new UpdateConfigurationCommand(
        serviceFeeBasisPoints: 1000,
        serviceFeeMinimumMinor: 100,
        serviceFeeMaximumMinor: 7000,
        initialOfferRadiusKm: 3,
        maxOfferRadiusKm: 12,
        orderRejectionReasons: ['out_of_stock', 'too_busy'],
        notificationDefaults: [
            'order_status' => ['email' => true, 'in_app' => false],
            'promotion' => ['email' => false, 'in_app' => false],
        ],
    );
}

beforeEach(function (): void {
    createPlatformConfigurationSchema();
});

it('returns the Platform defaults from every reader accessor when no row has been written', function (): void {
    $service = platformConfigurationServiceUnderTest();

    expect(DB::table('platform_configuration')->count())->toBe(0)
        ->and($service->serviceFeeBasisPoints())->toBe(PlatformConfigurationDefaults::SERVICE_FEE_PERCENTAGE_MIN_BP)
        ->and($service->offerRadii())->toBe([
            PlatformConfigurationDefaults::OFFER_RADIUS_KM_DEFAULT,
            PlatformConfigurationDefaults::OFFER_RADIUS_KM_MAX,
        ])
        ->and($service->orderRejectionReasons())->toBe(PlatformConfigurationDefaults::REJECTION_REASONS)
        ->and($service->notificationDefaults())->toBe(PlatformConfigurationDefaults::notificationDefaults());

    [$min, $max] = $service->serviceFeeBounds();

    expect($min->minorUnits)->toBe(PlatformConfigurationDefaults::DELIVERY_FEE_MIN_MINOR)
        ->and($max->minorUnits)->toBe(PlatformConfigurationDefaults::DELIVERY_FEE_MAX_MINOR)
        ->and($min->currency)->toBe(PlatformConfigurationDefaults::CURRENCY);

    // A reader always receives a usable Notification matrix: every category
    // enabled on every channel (Requirement 16.12).
    expect($service->notificationDefaults()['order_status']['email'])->toBeTrue();
});

it('persists an accepted update as the single row and reflects it from every reader accessor', function (): void {
    $service = platformConfigurationServiceUnderTest();

    $view = $service->update(validConfigurationCommand());

    // The write surface returns the stored values.
    expect($view->serviceFeeBasisPoints)->toBe(1000)
        ->and($view->initialOfferRadiusKm)->toBe(3)
        ->and($view->maxOfferRadiusKm)->toBe(12)
        ->and($view->orderRejectionReasons)->toBe(['out_of_stock', 'too_busy'])
        ->and($view->serviceFeeMinimum->minorUnits)->toBe(100)
        ->and($view->serviceFeeMaximum->minorUnits)->toBe(7000);

    // Exactly one row exists and it carries the submitted values.
    expect(DB::table('platform_configuration')->count())->toBe(1);

    // The reader seam reflects the same committed values.
    [$min, $max] = $service->serviceFeeBounds();

    expect($service->serviceFeeBasisPoints())->toBe(1000)
        ->and($service->offerRadii())->toBe([3, 12])
        ->and($service->orderRejectionReasons())->toBe(['out_of_stock', 'too_busy'])
        ->and($min->minorUnits)->toBe(100)
        ->and($max->minorUnits)->toBe(7000)
        ->and($service->notificationDefaults())->toBe([
            'order_status' => ['email' => true, 'in_app' => false],
            'promotion' => ['email' => false, 'in_app' => false],
        ]);
});

it('keeps exactly one row across repeated updates', function (): void {
    $service = platformConfigurationServiceUnderTest();

    $service->update(validConfigurationCommand());
    $service->update(new UpdateConfigurationCommand(
        serviceFeeBasisPoints: 2000,
        serviceFeeMinimumMinor: 0,
        serviceFeeMaximumMinor: 9000,
        initialOfferRadiusKm: 5,
        maxOfferRadiusKm: 20,
        orderRejectionReasons: ['kitchen_closed'],
        notificationDefaults: ['account' => ['email' => true, 'in_app' => true]],
    ));

    expect(DB::table('platform_configuration')->count())->toBe(1)
        ->and($service->serviceFeeBasisPoints())->toBe(2000)
        ->and($service->offerRadii())->toBe([5, 20]);
});

it('rejects a service-fee percentage above the ceiling and changes nothing', function (): void {
    $service = platformConfigurationServiceUnderTest();
    $service->update(validConfigurationCommand());

    $before = DB::table('platform_configuration')->first();

    $command = new UpdateConfigurationCommand(
        serviceFeeBasisPoints: 2501, // over the 2500 (25%) ceiling
        serviceFeeMinimumMinor: 100,
        serviceFeeMaximumMinor: 7000,
        initialOfferRadiusKm: 3,
        maxOfferRadiusKm: 12,
        orderRejectionReasons: ['out_of_stock'],
        notificationDefaults: ['order_status' => ['email' => true, 'in_app' => true]],
    );

    expect(fn () => $service->update($command))
        ->toThrow(ValidationException::class);

    // The stored row is byte-unchanged.
    expect(DB::table('platform_configuration')->first())->toEqual($before);
});

it('rejects a service-fee minimum greater than the maximum and changes nothing', function (): void {
    $service = platformConfigurationServiceUnderTest();
    $service->update(validConfigurationCommand());

    $before = DB::table('platform_configuration')->first();

    $command = new UpdateConfigurationCommand(
        serviceFeeBasisPoints: 1000,
        serviceFeeMinimumMinor: 9000,
        serviceFeeMaximumMinor: 100, // min > max
        initialOfferRadiusKm: 3,
        maxOfferRadiusKm: 12,
        orderRejectionReasons: ['out_of_stock'],
        notificationDefaults: ['order_status' => ['email' => true, 'in_app' => true]],
    );

    $thrown = null;
    try {
        $service->update($command);
    } catch (ValidationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->errorCode())->toBe('validation_failed')
        ->and($thrown->httpStatus())->toBe(422)
        ->and(DB::table('platform_configuration')->first())->toEqual($before);
});

it('rejects an offer radius outside the one-to-twenty-kilometre range and changes nothing', function (): void {
    $service = platformConfigurationServiceUnderTest();

    $command = new UpdateConfigurationCommand(
        serviceFeeBasisPoints: 1000,
        serviceFeeMinimumMinor: 100,
        serviceFeeMaximumMinor: 7000,
        initialOfferRadiusKm: 0, // below the 1 km floor
        maxOfferRadiusKm: 25,     // above the 20 km ceiling
        orderRejectionReasons: ['out_of_stock'],
        notificationDefaults: ['order_status' => ['email' => true, 'in_app' => true]],
    );

    expect(fn () => $service->update($command))
        ->toThrow(ValidationException::class);

    // Nothing was created.
    expect(DB::table('platform_configuration')->count())->toBe(0);
});
